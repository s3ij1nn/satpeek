<?php

namespace App\Filament\Resources;

use App\BotDetection\ScoreEngine;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Account')->schema([
                Forms\Components\TextInput::make('username')->disabled(),
                Forms\Components\TextInput::make('email')->disabled(),
                Forms\Components\TextInput::make('faucetpay_email'),
                Forms\Components\TextInput::make('referral_code')->disabled(),
            ])->columns(2),
            Forms\Components\Section::make('Balance')->schema([
                Forms\Components\TextInput::make('balance_sat')->numeric()->suffix('sat'),
                Forms\Components\TextInput::make('total_earned_sat')->numeric()->suffix('sat')->disabled(),
                Forms\Components\TextInput::make('total_withdrawn_sat')->numeric()->suffix('sat')->disabled(),
            ])->columns(3),
            Forms\Components\Section::make('Status')->schema([
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
            Forms\Components\Section::make('Bot detection')->schema([
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
                Tables\Actions\Action::make('rescore')
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
                Tables\Actions\EditAction::make(),
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
