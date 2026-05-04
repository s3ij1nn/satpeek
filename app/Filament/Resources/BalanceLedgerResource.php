<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\BalanceLedgerResource\Pages;
use App\Models\BalanceLedger;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Operator transaction audit. Most common support workflow:
 * "user X says they didn't get paid for view Y" → search by user,
 * filter to the relevant `reason` (`ptc_view` / `shortlink` /
 * `bitcotask_postback` / `referral_commission`) and the reference
 * id is right there on the row.
 *
 * Read-only on purpose. The ledger is the source of truth for
 * `users.balance_sat`; an admin write here would either silently
 * desync the cached balance OR (worse, with a manual rebalance) hide
 * a real accounting bug. Operators should issue corrections via the
 * existing typed actions (refund a withdrawal, etc.) which write
 * matched ledger pairs.
 */
class BalanceLedgerResource extends Resource
{
    protected static ?string $model = BalanceLedger::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Balance ledger';

    protected static ?int $navigationSort = 50;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    public static function table(Table $table): Table
    {
        // Single source of truth lives on the model — BalanceLedger::REASON_LABELS
        // is canonical for every reason ever written into the ledger. Adding a
        // new earning surface adds a constant + label there and this filter
        // picks it up on the next page render. Previously this list was
        // copy-pasted and silently went stale (internal_article + ad_funding
        // + ad_refund were missing as of v0.10.0).
        $reasons = BalanceLedger::REASON_LABELS;

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->since()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.username')
                    ->label('User')
                    ->searchable()
                    ->url(fn (BalanceLedger $r) => $r->user_id ? UserResource::getUrl('edit', ['record' => $r->user_id]) : null),
                Tables\Columns\TextColumn::make('reason')
                    ->badge()
                    ->searchable(),
                Tables\Columns\TextColumn::make('delta_sat')
                    ->label('Δ sat')
                    ->numeric()
                    ->sortable()
                    ->color(fn (BalanceLedger $r) => (int) $r->delta_sat >= 0 ? 'success' : 'danger')
                    ->formatStateUsing(fn ($state): string => ((int) $state >= 0 ? '+' : '').number_format((int) $state)),
                Tables\Columns\TextColumn::make('reference_type')
                    ->label('Ref type')
                    ->formatStateUsing(fn ($state): string => $state ? class_basename((string) $state) : '—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('reference_id')
                    ->label('Ref id')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('external_ref')
                    ->label('Ext ref')
                    ->limit(20)
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('reason')
                    ->options($reasons)
                    ->multiple()
                    ->searchable(),
                Tables\Filters\Filter::make('credit_only')
                    ->label('Credits only (Δ > 0)')
                    ->query(fn (Builder $q): Builder => $q->where('delta_sat', '>', 0))
                    ->toggle(),
                Tables\Filters\Filter::make('debit_only')
                    ->label('Debits only (Δ < 0)')
                    ->query(fn (Builder $q): Builder => $q->where('delta_sat', '<', 0))
                    ->toggle(),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100, 200]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBalanceLedgers::route('/'),
        ];
    }
}
