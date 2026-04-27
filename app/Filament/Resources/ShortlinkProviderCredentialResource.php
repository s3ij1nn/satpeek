<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShortlinkProviderCredentialResource\Pages;
use App\Models\ShortlinkProviderCredential;
use App\Shortlinks\Providers\ShortenerException;
use App\Shortlinks\ShortlinkProviderRegistry;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Operator UI for managing shortener API credentials.
 *
 * The form pre-fills sensible defaults from `config('satpeek.shortlink_providers.<name>')`
 * so the operator only needs to paste the api_token to bring a provider online.
 * The token field renders masked (•••), and the model casts api_token as
 * encrypted so a DB dump leak doesn't yield a usable token.
 */
class ShortlinkProviderCredentialResource extends Resource
{
    protected static ?string $model = ShortlinkProviderCredential::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Shortener APIs';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        $configured = (array) config('satpeek.shortlink_providers', []);

        return $schema->components([
            Schemas\Components\Section::make('Provider')->schema([
                Forms\Components\Select::make('name')
                    ->label('Provider')
                    ->options(collect($configured)
                        ->mapWithKeys(fn ($cfg, $name) => [$name => ($cfg['label'] ?? $name).' ('.$name.')'])
                        ->all())
                    ->searchable()
                    ->required()
                    ->live()
                    ->disabled(fn ($context) => $context === 'edit')
                    ->afterStateUpdated(function ($state, Set $set) use ($configured) {
                        $cfg = $configured[$state] ?? null;
                        if (! $cfg) {
                            return;
                        }
                        $set('label', $cfg['label'] ?? $state);
                        $set('transport', $cfg['transport'] ?? 'query');
                        $set('api_base', $cfg['api_base'] ?? '');
                    }),
                Forms\Components\TextInput::make('label')
                    ->maxLength(64)
                    ->helperText('Display label shown in the shortlink "Shorten via…" picker.'),
                Forms\Components\Select::make('transport')
                    ->options(['query' => 'Query token (btcut family)', 'path' => 'Path token (ouo family)'])
                    ->default('query')
                    ->required(),
                Forms\Components\TextInput::make('api_base')
                    ->label('API base URL')
                    ->url()
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('api_token')
                    ->label('API token')
                    ->password()
                    ->revealable()
                    ->maxLength(255)
                    ->helperText('Stored encrypted. Leave blank to keep the existing token unchanged.')
                    ->dehydrated(fn ($state) => filled($state)),
                Forms\Components\Toggle::make('is_active')
                    ->default(true)
                    ->helperText('Inactive providers are hidden from the shortlink picker even if a token is set.'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('label')->toggleable(),
                Tables\Columns\TextColumn::make('transport')
                    ->badge()
                    ->colors(['gray' => 'query', 'success' => 'path']),
                Tables\Columns\TextColumn::make('api_base')->limit(40)->toggleable(),
                Tables\Columns\IconColumn::make('has_token')
                    ->label('Token set')
                    ->getStateUsing(fn (ShortlinkProviderCredential $r) => filled($r->api_token))
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('last_used_at')->since()->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->since()->sortable()->toggleable(),
            ])
            ->actions([
                // Probe the live API with a throwaway URL to confirm the token
                // works without leaving the admin panel. Result lands in a
                // toast — the response URL is shown for sanity-check only.
                Actions\Action::make('test')
                    ->label('Test')
                    ->icon('heroicon-o-bolt')
                    ->color('warning')
                    ->visible(fn (ShortlinkProviderCredential $r) => filled($r->api_token) && $r->is_active)
                    ->action(function (ShortlinkProviderCredential $r) {
                        try {
                            // Force a fresh registry so the just-updated row is picked up.
                            app()->forgetInstance(ShortlinkProviderRegistry::class);
                            $client = app(ShortlinkProviderRegistry::class)->get($r->name);
                            $short = $client->shorten('https://example.com/satpeek-credential-test');
                            $r->update(['last_used_at' => now()]);
                            Notification::make()
                                ->title("`{$r->name}` works")
                                ->body("Returned: {$short}")
                                ->success()
                                ->send();
                        } catch (ShortenerException $e) {
                            Notification::make()
                                ->title("`{$r->name}` failed")
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShortlinkProviderCredentials::route('/'),
            'create' => Pages\CreateShortlinkProviderCredential::route('/create'),
            'edit' => Pages\EditShortlinkProviderCredential::route('/{record}/edit'),
        ];
    }
}
