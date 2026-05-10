<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\SystemAuditLog;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Last-5 SystemAuditLog entries (warning + error level only) on the
 * dashboard so operators see recent system events at a glance
 * without drilling into the full audit resource.
 *
 * Why warning/error only: info-level rows are noise on a digest
 * dashboard. The dashboard's job is "show me what's wrong"; the
 * resource's job is "let me dig into the full trail".
 *
 * The widget hides itself entirely (canView returns false) when
 * there are no recent warning/error rows — a clean deploy doesn't
 * see "(empty)" boilerplate cluttering the dashboard.
 */
class SystemAuditLogTailWidget extends TableWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent system events (last 5 warnings/errors)')
            ->query(
                SystemAuditLog::query()
                    ->whereIn('level', ['warning', 'error'])
                    ->orderByDesc('occurred_at')
                    ->limit(5),
            )
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')
                    ->label('When')
                    ->since(),
                Tables\Columns\TextColumn::make('level')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'error' ? 'danger' : 'warning'),
                Tables\Columns\TextColumn::make('source')
                    ->fontFamily('mono'),
                Tables\Columns\TextColumn::make('summary')
                    ->wrap(),
            ])
            ->paginated(false);
    }

    public static function canView(): bool
    {
        return SystemAuditLog::query()
            ->whereIn('level', ['warning', 'error'])
            ->exists();
    }
}
