<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CaptchaChallengeResource\Pages;
use App\Models\CaptchaChallenge;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Read-only triage surface for captcha solve attempts.
 *
 * The verifier already persists the per-signal detail
 * (solve_ms, shape_distance_px, dt_median_ms, dt_jitter_ratio,
 * jerk_entropy, completion_dwell_ms) plus the final confidence
 * score into `captcha_challenges.meta` JSON. Without an admin
 * surface the operator was reading those rows via psql or
 * tinker — this resource lifts the same data into the
 * Operations group so a "why was my submission rejected?"
 * support ticket can be answered with a single deep-link.
 *
 * Read-only by design: mutating a row would invalidate the
 * audit trail (verifier only writes once per challenge).
 *
 * Mirror of PtcViewResource / ShortlinkClickResource.
 */
class CaptchaChallengeResource extends Resource
{
    protected static ?string $model = CaptchaChallenge::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Captcha attempts';

    protected static ?int $navigationSort = 27;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('user.username')
                    ->label('User')
                    ->placeholder('— anon —')
                    ->searchable()
                    ->url(fn (CaptchaChallenge $r) => $r->user_id ? UserResource::getUrl('edit', ['record' => $r->user_id]) : null),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => 'issued',
                        'success' => 'verified',
                        'danger' => 'rejected',
                        'warning' => 'expired',
                        'info' => 'consumed',
                    ])
                    ->sortable(),
                Tables\Columns\TextColumn::make('rejection_reason')
                    ->placeholder('—')
                    ->fontFamily('mono')
                    ->searchable(),
                // Operator-facing per-signal triage. Pulled out of the
                // verifier-saved meta JSON so a "why rejected?" ticket
                // can be answered in one row scroll without reading raw JSON.
                Tables\Columns\TextColumn::make('solve_ms')
                    ->label('Solve ms')
                    ->getStateUsing(fn (CaptchaChallenge $r): string => self::extractSignal($r, 'solve_ms'))
                    ->fontFamily('mono')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('shape_distance_px')
                    ->label('Shape Δpx')
                    ->getStateUsing(fn (CaptchaChallenge $r): string => self::extractSignal($r, 'shape_distance_px'))
                    ->fontFamily('mono')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('jerk_entropy')
                    ->label('Jerk H')
                    ->getStateUsing(fn (CaptchaChallenge $r): string => self::extractSignal($r, 'jerk_entropy'))
                    ->fontFamily('mono')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('dt_jitter_ratio')
                    ->label('Δt jitter')
                    ->getStateUsing(fn (CaptchaChallenge $r): string => self::extractSignal($r, 'dt_jitter_ratio'))
                    ->fontFamily('mono')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('confidence')
                    ->getStateUsing(fn (CaptchaChallenge $r): string => self::extractSignal($r, 'confidence', '—', 3))
                    ->fontFamily('mono')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('client_ip')
                    ->label('IP')
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('issued_at')->since()->sortable(),
                Tables\Columns\TextColumn::make('resolved_at')->since()->placeholder('—')->sortable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'issued' => 'issued',
                        'verified' => 'verified',
                        'rejected' => 'rejected',
                        'expired' => 'expired',
                        'consumed' => 'consumed',
                    ]),
                Tables\Filters\SelectFilter::make('provider')
                    ->options([
                        'trajectory_trace' => 'trajectory_trace',
                    ]),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    /**
     * Pull a single signal out of the verifier-saved meta blob and
     * format it for the table cell. Confidence sits at the top level
     * of meta; per-signal stats sit under meta.signals (set by
     * `ChallengeVerifier::verify()`).
     */
    private static function extractSignal(CaptchaChallenge $r, string $key, string $placeholder = '—', int $decimals = 2): string
    {
        $meta = (array) $r->meta;
        if ($key === 'confidence') {
            $value = $meta['confidence'] ?? null;
        } else {
            $value = (array) ($meta['signals'] ?? []);
            $value = $value[$key] ?? null;
        }
        if ($value === null) {
            return $placeholder;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return number_format($value, $decimals);
        }

        return (string) $value;
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
            'index' => Pages\ListCaptchaChallenges::route('/'),
        ];
    }
}
