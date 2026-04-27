<?php

namespace App\Filament\Resources;

use App\BotDetection\ScoreEngine;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Models\UserIpObservation;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Schemas\Components\Section::make('Account')->schema([
                Forms\Components\TextInput::make('username')->disabled(),
                Forms\Components\TextInput::make('email')->disabled(),
                Forms\Components\TextInput::make('faucetpay_email'),
                Forms\Components\TextInput::make('referral_code')->disabled(),
            ])->columns(2),
            Schemas\Components\Section::make('Balance')->schema([
                Forms\Components\TextInput::make('balance_sat')->numeric()->suffix('sat'),
                Forms\Components\TextInput::make('total_earned_sat')->numeric()->suffix('sat')->disabled(),
                Forms\Components\TextInput::make('total_withdrawn_sat')->numeric()->suffix('sat')->disabled(),
            ])->columns(3),
            Schemas\Components\Section::make('Status')->schema([
                Forms\Components\Toggle::make('is_admin'),
                Forms\Components\Toggle::make('is_banned'),
                Forms\Components\Textarea::make('ban_reason')->rows(2),
            ])->columns(3),

            // Bot detection panel — operator-only visibility into the
            // ScoreEngine verdict driving this user's tier. All fields
            // are sourced from the user's BotScore relation; absent
            // when the user has never been evaluated (brand new account
            // pre-auth). Re-score action recomputes signals on demand
            // for triage.
            Schemas\Components\Section::make('Bot detection')->schema([
                Forms\Components\Placeholder::make('bot_tier')
                    ->label('Tier')
                    // @phpstan-ignore-next-line nullsafe.neverNull
                    ->content(fn (?User $record): string => $record?->botScore?->tier ?? 'trust (no eval yet)'),
                Forms\Components\Placeholder::make('bot_score_value')
                    ->label('Score')
                    ->content(fn (?User $record): string => $record?->botScore?->score !== null
                        ? number_format((float) $record->botScore->score, 3)
                        : '—'),
                Forms\Components\Placeholder::make('bot_score_evaluated_at')
                    ->label('Last evaluated')
                    ->content(fn (?User $record): string => $record?->botScore?->last_evaluated_at?->diffForHumans() ?? '—'),
                Forms\Components\Placeholder::make('signals_breakdown')
                    ->label('Signal breakdown (weight · raw)')
                    ->columnSpanFull()
                    ->content(function (?User $record): HtmlString {
                        // @phpstan-ignore-next-line nullsafe.neverNull
                        $signals = (array) ($record?->botScore?->signals ?? []);
                        if ($signals === []) {
                            return new HtmlString('<em style="color:#888">No signals recorded.</em>');
                        }
                        $rows = '';
                        foreach ($signals as $name => $info) {
                            $weight = (float) ($info['weight'] ?? 0);
                            $raw = (float) ($info['raw'] ?? 0);
                            $rows .= sprintf(
                                '<tr><td style="font-family:monospace;padding-right:1rem">%s</td><td style="padding-right:1rem">%.2f</td><td>%.3f</td></tr>',
                                e((string) $name),
                                $weight,
                                $raw,
                            );
                        }

                        return new HtmlString(
                            '<table style="border-collapse:collapse;font-size:0.875rem">'.
                            '<thead><tr><th style="text-align:left;padding-right:1rem">Signal</th><th style="text-align:left;padding-right:1rem">Weight</th><th style="text-align:left">Raw</th></tr></thead>'.
                            '<tbody>'.$rows.'</tbody></table>'
                        );
                    }),
            ])->columns(3),

            // Inline auth IP history — saves the operator a navigation
            // hop to /admin/user-ip-observations during triage. Shows the
            // 10 most recent observations with hit_count + sibling count
            // (distinct OTHER user_ids on the same IP). Allowlist-aware
            // is intentionally NOT applied here: the operator wants to
            // see the raw evidence, not a sanitised view.
            Schemas\Components\Section::make('Recent IP history')->schema([
                Forms\Components\Placeholder::make('recent_ips')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->content(function (?User $record): HtmlString {
                        if ($record === null) {
                            return new HtmlString('<em style="color:#888">—</em>');
                        }
                        $rows = UserIpObservation::query()
                            ->where('user_id', $record->id)
                            ->orderByDesc('last_seen_at')
                            ->limit(10)
                            ->get();
                        if ($rows->isEmpty()) {
                            return new HtmlString('<em style="color:#888">No auth observations yet.</em>');
                        }
                        $html = '<table style="border-collapse:collapse;font-size:0.875rem;width:100%">'.
                            '<thead><tr>'.
                            '<th style="text-align:left;padding-right:1rem">IP</th>'.
                            '<th style="text-align:left;padding-right:1rem">Source</th>'.
                            '<th style="text-align:left;padding-right:1rem">Hits</th>'.
                            '<th style="text-align:left;padding-right:1rem">Siblings</th>'.
                            '<th style="text-align:left">Last seen</th>'.
                            '</tr></thead><tbody>';
                        foreach ($rows as $row) {
                            $siblings = (int) UserIpObservation::query()
                                ->where('ip', $row->ip)
                                ->where('user_id', '!=', $record->id)
                                ->distinct()
                                ->count('user_id');
                            $color = $siblings === 0 ? '#22c55e' : ($siblings >= 3 ? '#ef4444' : '#eab308');
                            $html .= sprintf(
                                '<tr><td style="font-family:monospace;padding-right:1rem">%s</td><td style="padding-right:1rem">%s</td><td style="padding-right:1rem">%d</td><td style="color:%s;padding-right:1rem">%d</td><td>%s</td></tr>',
                                e((string) $row->ip),
                                e((string) $row->source),
                                (int) $row->hit_count,
                                $color,
                                $siblings,
                                e($row->last_seen_at->diffForHumans()),
                            );
                        }
                        $html .= '</tbody></table>';
                        $html .= sprintf(
                            '<p style="margin-top:.5rem;font-size:0.8rem"><a href="%s" style="color:#3b82f6">Open full IP observations list →</a></p>',
                            e(url('/admin/user-ip-observations?tableSearch='.urlencode((string) $record->username))),
                        );

                        return new HtmlString($html);
                    }),
            ])->collapsed(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('username')->searchable(),
                Tables\Columns\TextColumn::make('email')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('balance_sat')->label('Balance')->suffix(' sat')->sortable()->numeric(),
                Tables\Columns\TextColumn::make('total_earned_sat')->label('Earned')->suffix(' sat')->numeric()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('botScore.tier')
                    ->label('Tier')
                    ->badge()
                    ->colors([
                        'success' => 'trust',
                        'warning' => 'suspect',
                        'danger' => fn ($state) => in_array($state, ['likely_bot', 'banned'], true),
                    ])
                    ->placeholder('trust'),
                Tables\Columns\TextColumn::make('botScore.score')->label('Score')->numeric(decimalPlaces: 2)->placeholder('0.00'),
                Tables\Columns\IconColumn::make('is_admin')->boolean(),
                Tables\Columns\IconColumn::make('is_banned')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_banned'),
                Tables\Filters\TernaryFilter::make('is_admin'),
            ])
            ->actions([
                Actions\Action::make('rescore')
                    ->label('Re-score')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Force a fresh ScoreEngine pass for this user, ignoring the throttle window. Useful for triaging a manual ban / unban decision.')
                    ->action(function (User $record): void {
                        $row = app(ScoreEngine::class)->evaluate($record);
                        Notification::make()
                            ->title('Re-scored '.$record->username)
                            ->body(sprintf('Tier: %s · Score: %.3f', $row->tier, (float) $row->score))
                            ->success()
                            ->send();
                    }),
                Actions\EditAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
