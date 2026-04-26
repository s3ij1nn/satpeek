<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShortlinkResource\Pages;
use App\Models\Shortlink;
use App\Shortlinks\Providers\ShortenerException;
use App\Shortlinks\ShortlinkProviderRegistry;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ShortlinkResource extends Resource
{
    protected static ?string $model = Shortlink::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Shortlinks';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Shortlink')->schema([
                Forms\Components\TextInput::make('title')->required()->maxLength(200),
                Forms\Components\TextInput::make('target_url')->label('Target URL')->url()->required()->maxLength(500),
            ]),
            Forms\Components\Section::make('Reward & timing')->schema([
                Forms\Components\TextInput::make('reward_sat')->numeric()->required()->default(3)->minValue(1),
                Forms\Components\TextInput::make('hold_seconds')->label('Hold (seconds)')->numeric()->required()->default(10)->minValue(3)->maxValue(120),
                Forms\Components\TextInput::make('daily_limit_per_user')->numeric()->required()->default(5)->minValue(1),
            ])->columns(3),
            Forms\Components\Section::make('Source & status')->schema([
                Forms\Components\Select::make('source')
                    ->options([
                        'internal' => 'Internal (own inventory)',
                        'mock' => 'Mock',
                        'bitcotask' => 'BitcoTask',
                    ])
                    ->default('internal')
                    ->required(),
                Forms\Components\TextInput::make('external_id')
                    ->default(fn () => 'internal-'.Str::lower(Str::random(8))),
                Forms\Components\Toggle::make('is_active')->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('source')->badge()->colors([
                    'success' => 'internal',
                    'gray' => 'mock',
                    'warning' => 'bitcotask',
                ]),
                Tables\Columns\TextColumn::make('title')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('reward_sat')->suffix(' sat')->sortable(),
                Tables\Columns\TextColumn::make('hold_seconds')->suffix(' s'),
                Tables\Columns\TextColumn::make('daily_limit_per_user')->label('Daily/user'),
                Tables\Columns\TextColumn::make('verified_clicks')
                    ->label('Verified')
                    ->getStateUsing(fn ($record) => \App\Models\ShortlinkClick::where('shortlink_id', $record->id)->where('status', 'verified')->count())
                    ->numeric()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('attempts')
                    ->label('Attempts')
                    ->getStateUsing(fn ($record) => \App\Models\ShortlinkClick::where('shortlink_id', $record->id)->count())
                    ->numeric()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('total_paid_sat')
                    ->label('Paid out')
                    ->getStateUsing(function ($record) {
                        $verified = \App\Models\ShortlinkClick::where('shortlink_id', $record->id)->where('status', 'verified')->count();
                        return number_format($verified * (int) $record->reward_sat).' sat';
                    })
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('source')->options([
                    'internal' => 'Internal',
                    'mock' => 'Mock',
                    'bitcotask' => 'BitcoTask',
                ]),
                Tables\Filters\TernaryFilter::make('is_active'),
            ])
            ->actions([
                // Shortens the row's `target_url` through a configured publisher
                // shortener (btcut.io et al.) and overwrites it with the returned
                // shortened URL. The action is only visible when at least one
                // provider has a token configured in .env.
                Tables\Actions\Action::make('shorten')
                    ->label('Shorten via…')
                    ->icon('heroicon-o-scissors')
                    ->color('warning')
                    ->visible(fn () => count(app(ShortlinkProviderRegistry::class)->configuredNames()) > 0)
                    ->form(function () {
                        $registry = app(ShortlinkProviderRegistry::class);
                        $opts = [];
                        foreach ($registry->configuredNames() as $name) {
                            $label = (string) (config("satpeek.shortlink_providers.{$name}.label") ?? $name);
                            $opts[$name] = $label;
                        }
                        return [
                            Forms\Components\Select::make('provider')
                                ->options($opts)
                                ->required()
                                ->default(array_key_first($opts)),
                            Forms\Components\TextInput::make('alias')
                                ->helperText('Optional custom slug. Leave blank for auto-generated.')
                                ->maxLength(64),
                        ];
                    })
                    ->action(function (Shortlink $record, array $data) {
                        try {
                            $client = app(ShortlinkProviderRegistry::class)->get((string) $data['provider']);
                            $short = $client->shorten($record->target_url, $data['alias'] ?? null);
                            $record->update(['target_url' => $short, 'source' => $client->name()]);
                            Notification::make()
                                ->title('Shortened')
                                ->body("Target URL replaced with {$short}")
                                ->success()
                                ->send();
                        } catch (ShortenerException $e) {
                            Notification::make()
                                ->title('Shortener failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShortlinks::route('/'),
            'create' => Pages\CreateShortlink::route('/create'),
            'edit' => Pages\EditShortlink::route('/{record}/edit'),
        ];
    }
}
