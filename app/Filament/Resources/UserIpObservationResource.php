<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\UserIpObservationResource\Pages;
use App\Models\UserIpObservation;
use App\Services\UserIpObserver;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Operator audit surface for the multi-account-by-IP detection.
 *
 * Each row is "user X authenticated from IP Y" — appended at login /
 * register submit by {@see UserIpObserver}. The
 * `siblings` derived column shows how many DISTINCT OTHER user_ids
 * have used the same IP, so a quick sort by that column surfaces
 * sock-puppet clusters without leaving the page.
 *
 * Read-only by design. The audit trail is an append-only signal; an
 * accidental admin delete would silently remove evidence the
 * `SharedIpSignal` and the operator's manual review both depend on.
 * Use the user-link column to jump to the User row when an action
 * (warn / hold withdrawals / ban) is needed; never edit observations
 * in place.
 */
class UserIpObservationResource extends Resource
{
    protected static ?string $model = UserIpObservation::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'IP observations';

    protected static ?int $navigationSort = 35;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('user.username')
                    ->label('User')
                    ->searchable()
                    ->url(fn (UserIpObservation $r) => UserResource::getUrl('edit', ['record' => $r->user_id])),
                Tables\Columns\TextColumn::make('ip')
                    ->label('IP')
                    ->fontFamily('mono')
                    ->copyable()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('siblings')
                    ->label('Other users on this IP')
                    ->numeric()
                    ->getStateUsing(fn (UserIpObservation $r): int => UserIpObservation::query()
                        ->where('ip', $r->ip)
                        ->where('user_id', '!=', $r->user_id)
                        ->distinct()
                        ->count('user_id'))
                    ->badge()
                    ->color(fn ($state) => $state === 0 ? 'success' : ($state >= 3 ? 'danger' : 'warning')),
                Tables\Columns\TextColumn::make('source')
                    ->badge()
                    ->colors(['gray' => 'login', 'info' => 'register']),
                Tables\Columns\TextColumn::make('hit_count')
                    ->label('Hits')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('first_seen_at')->since()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('last_seen_at')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('source')
                    ->options(['login' => 'login', 'register' => 'register']),
                Tables\Filters\TernaryFilter::make('shared_only')
                    ->label('Shared IPs only')
                    ->placeholder('All observations')
                    ->trueLabel('Shared with another user')
                    ->falseLabel('Unique to this user')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereExists(function ($sub): void {
                            $sub->selectRaw('1')
                                ->from('user_ip_observations as sib')
                                ->whereColumn('sib.ip', 'user_ip_observations.ip')
                                ->whereColumn('sib.user_id', '!=', 'user_ip_observations.user_id');
                        }),
                        false: fn (Builder $q): Builder => $q->whereNotExists(function ($sub): void {
                            $sub->selectRaw('1')
                                ->from('user_ip_observations as sib')
                                ->whereColumn('sib.ip', 'user_ip_observations.ip')
                                ->whereColumn('sib.user_id', '!=', 'user_ip_observations.user_id');
                        }),
                        blank: fn (Builder $q): Builder => $q,
                    ),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->paginated([25, 50, 100]);
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
            'index' => Pages\ListUserIpObservations::route('/'),
        ];
    }
}
