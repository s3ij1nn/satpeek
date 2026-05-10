<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SystemAuditLogResource\Pages;
use App\Models\SystemAuditLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Read-only system-event timeline. Counterpart to AdminAuditLog —
 * where AdminAuditLog records UI mutations, this captures what the
 * SYSTEM did to itself: dead-lettered jobs, watcher-cron skips,
 * RPC outages, etc.
 *
 * Listing-only by design — touching a row would defeat the audit
 * purpose. The recording side is `SystemAuditLog::record()` invoked
 * from each failure handler that should be tracked.
 */
class SystemAuditLogResource extends Resource
{
    protected static ?string $model = SystemAuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bug-ant';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'System audit log';

    protected static ?int $navigationSort = 41;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('source')
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('level')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'error' => 'danger',
                        'warning' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('summary')
                    ->limit(80)
                    ->wrap(),
                Tables\Columns\TextColumn::make('detail')
                    ->limit(80)
                    ->formatStateUsing(fn ($state): string => $state === null
                        ? '—'
                        : (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('occurred_at')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('level')
                    ->options([
                        'info' => 'info',
                        'warning' => 'warning',
                        'error' => 'error',
                    ]),
                Tables\Filters\SelectFilter::make('source')
                    ->options(fn () => SystemAuditLog::query()
                        ->select('source')
                        ->distinct()
                        ->orderBy('source')
                        ->pluck('source', 'source')
                        ->all()),
            ])
            ->defaultSort('id', 'desc')
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
            'index' => Pages\ListSystemAuditLogs::route('/'),
        ];
    }
}
