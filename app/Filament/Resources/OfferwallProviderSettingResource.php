<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OfferwallProviderSettingResource\Pages;
use App\Models\OfferwallProviderSetting;
use App\Providers\AppServiceProvider;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Operator UI for flipping offerwall publisher integrations on/off without
 * a redeploy. Resolves at request time via
 * {@see AppServiceProvider::applyOfferwallDbOverrides()} —
 * a row's `is_enabled` flag wins over the env-driven `OFFERWALLS_ENABLED`
 * list.
 *
 * Credentials (BITCOTASK_API_KEY / BITCOTASK_BEARER_TOKEN /
 * BITCOTASK_S2S_SECRET) intentionally stay in env. Putting them here
 * would widen the secret-leak surface (DB dumps, replicas) without the
 * same hardening env-stage secrets typically get.
 */
class OfferwallProviderSettingResource extends Resource
{
    protected static ?string $model = OfferwallProviderSetting::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Offerwall toggle';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Schemas\Components\Section::make('Provider')->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Provider name')
                    ->required()
                    ->maxLength(32)
                    ->disabled(fn ($context) => $context === 'edit')
                    ->helperText('Adapter key registered in AppServiceProvider — e.g. `bitcotask`.'),
                Forms\Components\Toggle::make('is_enabled')
                    ->label('Enabled')
                    ->default(false)
                    ->helperText('Overrides OFFERWALLS_ENABLED. Off = excluded even if env lists it. On = included even if env omits it.'),
                Forms\Components\Textarea::make('notes')
                    ->maxLength(500)
                    ->rows(3)
                    ->helperText('Free-form ops note — e.g. publisher review status, contact email.'),
            ])->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\IconColumn::make('is_enabled')->boolean(),
                Tables\Columns\TextColumn::make('notes')->limit(60)->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->since()->sortable(),
            ])
            ->actions([
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
            'index' => Pages\ListOfferwallProviderSettings::route('/'),
            'create' => Pages\CreateOfferwallProviderSetting::route('/create'),
            'edit' => Pages\EditOfferwallProviderSetting::route('/{record}/edit'),
        ];
    }
}
