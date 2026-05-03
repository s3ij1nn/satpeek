<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BotSignalWeightResource\Pages;
use App\Models\BotSignalWeight;
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
 * Operator UI for tuning per-signal weights without redeploying.
 *
 * Defaults still ship in `config/satpeek.php`. A row here shadows
 * the config default at boot time
 * (see {@see AppServiceProvider::applyBotSignalWeightOverrides()}).
 *
 * Workflow: noisy signal flagging too many honest users → drop its
 * weight here without code change. New high-precision positive signal
 * underweighted at install time → bump it up. `is_enabled=false` is
 * the kill switch (sets the runtime weight to 0 but keeps the signal
 * in the BotScore.signals JSON for transparency).
 */
class BotSignalWeightResource extends Resource
{
    protected static ?string $model = BotSignalWeight::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-vertical';

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Bot signal weights';

    protected static ?int $navigationSort = 35;

    public static function form(Schema $schema): Schema
    {
        // Pre-override snapshot captured by AppServiceProvider boot.
        // Reading `bot_score.weights` directly here would surface the
        // post-override values (the operator's own saved row) as the
        // "default", losing visibility into the original config ship
        // value the moment the first override is saved.
        $defaults = (array) config('satpeek.bot_score.default_weights', config('satpeek.bot_score.weights', []));
        $names = array_keys($defaults);

        return $schema->components([
            Schemas\Components\Section::make('Signal')->schema([
                Forms\Components\Select::make('name')
                    ->label('Signal')
                    ->options(collect($names)->mapWithKeys(
                        fn (string $n): array => [$n => $n.'  (default '.number_format((float) ($defaults[$n] ?? 0), 3).')'],
                    )->all())
                    ->searchable()
                    ->required()
                    ->disabled(fn ($context) => $context === 'edit')
                    ->helperText('Pick one of the registered signals. Names match the keys in config/satpeek.php > bot_score.weights.'),
                Forms\Components\TextInput::make('weight')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->maxValue(1)
                    ->step(0.001)
                    ->helperText('0.000 – 1.000. ScoreEngine renormalises by total weight, so the relative ratio is what matters, not the absolute value.'),
                Forms\Components\Toggle::make('is_enabled')
                    ->default(true)
                    ->helperText('Off = the signal still evaluates (for transparency in BotScore.signals JSON) but its weight is forced to 0 in the composite score.'),
                Forms\Components\Textarea::make('notes')
                    ->rows(2)
                    ->maxLength(500)
                    ->helperText('Optional — why this override exists. Useful for the next operator who wonders why the weight is off-default.'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        // Pre-override snapshot captured by AppServiceProvider boot.
        // Reading `bot_score.weights` directly here would surface the
        // post-override values (the operator's own saved row) as the
        // "default", losing visibility into the original config ship
        // value the moment the first override is saved.
        $defaults = (array) config('satpeek.bot_score.default_weights', config('satpeek.bot_score.weights', []));

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('weight')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),
                Tables\Columns\TextColumn::make('default_weight')
                    ->label('Default')
                    ->getStateUsing(fn (BotSignalWeight $r): string => number_format((float) ($defaults[$r->name] ?? 0), 3))
                    ->fontFamily('mono')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_enabled')->boolean(),
                Tables\Columns\TextColumn::make('notes')->limit(40)->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')->since()->sortable()->toggleable(),
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
            'index' => Pages\ListBotSignalWeights::route('/'),
            'create' => Pages\CreateBotSignalWeight::route('/create'),
            'edit' => Pages\EditBotSignalWeight::route('/{record}/edit'),
        ];
    }
}
