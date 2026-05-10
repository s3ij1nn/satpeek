<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WithdrawalResource\Pages;
use App\Mail\WithdrawalRejectedEmail;
use App\Enums\WithdrawalStatus;
use App\Models\BalanceLedger;
use App\Models\Withdrawal;
use App\Services\AdminAuditor;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use UnitEnum;

class WithdrawalResource extends Resource
{
    protected static ?string $model = Withdrawal::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 5;

    public static function getNavigationBadge(): ?string
    {
        $pending = Withdrawal::whereIn('status', ['hold', 'queued'])->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return Withdrawal::where('status', 'hold')->exists() ? 'warning' : 'gray';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Schemas\Components\Section::make('Withdrawal')->schema([
                Forms\Components\TextInput::make('user.email')->disabled()->label('User'),
                Forms\Components\TextInput::make('amount_sat')->suffix('sat')->disabled(),
                Forms\Components\TextInput::make('faucetpay_email')->disabled(),
                Forms\Components\TextInput::make('currency')->disabled(),
                Forms\Components\Select::make('status')->options([
                    'queued' => 'queued',
                    'hold' => 'hold (awaiting review)',
                    'processing' => 'processing',
                    'sent' => 'sent',
                    'failed' => 'failed',
                    'rejected' => 'rejected',
                ])->required(),
                Forms\Components\Textarea::make('failure_reason')->rows(2),
            ])->columns(2),

            // FaucetPay retry telemetry — populated by ProcessWithdrawalJob
            // (auto-retry on FaucetPayUnreachableException, $tries=3 with
            // [60, 300, 1800] backoff). Surfaced here so the operator can
            // tell at a glance whether a `processing` row is actively
            // retrying or stuck for an external reason.
            Schemas\Components\Section::make('Job retry telemetry')->schema([
                Forms\Components\Placeholder::make('attempts')
                    ->label('Attempts')
                    ->content(fn (?Withdrawal $r): string => (string) (int) (((array) $r?->meta)['attempts'] ?? 0)),
                Forms\Components\Placeholder::make('last_attempted')
                    ->label('Last attempt')
                    ->content(function (?Withdrawal $r): string {
                        $stamp = ((array) $r?->meta)['last_attempted_at'] ?? null;
                        if (! is_string($stamp) || $stamp === '') {
                            return '—';
                        }

                        return Carbon::parse($stamp)->diffForHumans();
                    }),
                Forms\Components\Placeholder::make('payout_response')
                    ->label('FaucetPay response')
                    ->columnSpanFull()
                    ->content(function (?Withdrawal $r): string {
                        $resp = ((array) $r?->meta)['response'] ?? null;
                        if (! is_array($resp) || $resp === []) {
                            return '—';
                        }

                        return json_encode($resp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '—';
                    }),
            ])->columns(2)->collapsible()->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('user.email')->label('User')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('amount_sat')->label('Debited')->suffix(' sat')->sortable()->numeric(),
                Tables\Columns\TextColumn::make('payout_method')
                    ->label('Route')
                    ->badge()
                    ->colors(['warning' => 'faucetpay', 'success' => 'onchain'])
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('payout_currency')
                    ->label('Currency')
                    ->badge()
                    ->placeholder(fn (Withdrawal $r): string => strtoupper((string) $r->currency)),
                Tables\Columns\TextColumn::make('payout_amount')
                    ->label('Payout')
                    ->placeholder('—')
                    ->fontFamily('mono')
                    ->description(fn (Withdrawal $r): ?string => $r->payout_currency ? 'in '.$r->payout_currency.' smallest unit' : null)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('destination')
                    ->label('Pay to')
                    ->searchable()
                    ->copyable()
                    ->placeholder(fn (Withdrawal $r): ?string => $r->faucetpay_email),
                Tables\Columns\TextColumn::make('fee_sat')
                    ->label('Fee')
                    ->suffix(' sat')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')->badge()->colors([
                    'gray' => 'queued',
                    'warning' => 'hold',
                    'info' => 'processing',
                    'success' => 'sent',
                    'danger' => fn ($state) => in_array($state, ['failed', 'rejected'], true),
                ])->sortable(),
                Tables\Columns\IconColumn::make('requires_review')->boolean(),
                Tables\Columns\TextColumn::make('faucetpay_payout_id')
                    ->label('FP payout id')
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('onchain_tx_hash')
                    ->label('Tx hash')
                    ->placeholder('—')
                    ->limit(14)
                    ->copyable()
                    ->copyMessage('Copied tx hash')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('attempts')
                    ->label('Tries')
                    ->getStateUsing(fn (Withdrawal $r): int => (int) (((array) $r->meta)['attempts'] ?? 0))
                    ->badge()
                    ->color(fn ($state) => $state >= 3 ? 'danger' : ($state >= 1 ? 'warning' : 'gray'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('processed_at')->dateTime()->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'queued' => 'queued',
                    'hold' => 'hold',
                    'processing' => 'processing',
                    'sent' => 'sent',
                    'failed' => 'failed',
                    'rejected' => 'rejected',
                ]),
                Tables\Filters\TernaryFilter::make('requires_review'),
            ])
            ->actions([
                Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Withdrawal $r) => $r->status === WithdrawalStatus::Hold)
                    ->action(function (Withdrawal $r) {
                        // Atomic claim: row MUST still be `hold` for the
                        // transition to fire. visible() filters render-time
                        // but two concurrent admin tabs both see `hold`,
                        // both pass the visibility check, both enter here —
                        // without this WHERE the second admin's audit log
                        // shadows the first with no real state change.
                        $changed = Withdrawal::where('id', $r->id)
                            ->where('status', 'hold')
                            ->update([
                                'status' => 'queued',
                                'requires_review' => false,
                                'reviewed_by' => Auth::id(),
                            ]);
                        if ($changed === 0) {
                            return; // another admin won the race; no audit
                        }
                        AdminAuditor::record('withdrawal.approve', $r, [
                            'amount_sat' => (int) $r->amount_sat,
                        ]);
                    }),
                Actions\Action::make('reject')
                    ->label('Reject & refund')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Withdrawal $r) => in_array($r->status, [WithdrawalStatus::Hold, WithdrawalStatus::Queued], true))
                    ->schema([
                        Forms\Components\Textarea::make('failure_reason')
                            ->label('Reason (visible to user)')
                            ->required(),
                    ])
                    ->action(function (Withdrawal $r, array $data) {
                        $refunded = (int) $r->amount_sat;
                        // Atomic claim INSIDE the transaction — two admin
                        // tabs hitting Reject simultaneously both see
                        // status=hold at render time, both pass visible(),
                        // both reach this transaction. Without this WHERE
                        // they would both refund and the second's
                        // BalanceLedger::create would fatal on the partial
                        // UNIQUE (reason, reference_type, reference_id)
                        // — but the first refund's increment is already
                        // committed, leaving the user double-credited
                        // until the constraint surfaces. The marked === 0
                        // bail-out handles the loser cleanly.
                        $settled = DB::transaction(function () use ($r, $data, $refunded): bool {
                            $marked = Withdrawal::where('id', $r->id)
                                ->whereIn('status', ['hold', 'queued'])
                                ->update([
                                    'status' => 'rejected',
                                    'requires_review' => false,
                                    'reviewed_by' => Auth::id(),
                                    'failure_reason' => $data['failure_reason'],
                                    'processed_at' => Carbon::now(),
                                ]);
                            if ($marked === 0) {
                                return false;
                            }
                            BalanceLedger::create([
                                'user_id' => $r->user_id,
                                'delta_sat' => $refunded,
                                'reason' => BalanceLedger::REASON_WITHDRAW_REJECTED,
                                'reference_type' => Withdrawal::class,
                                'reference_id' => $r->id,
                            ]);
                            $r->user->increment('balance_sat', $refunded);

                            return true;
                        });
                        if (! $settled) {
                            return; // another admin / job won the race
                        }
                        AdminAuditor::record('withdrawal.reject', $r, [
                            'failure_reason' => $data['failure_reason'],
                            'refunded_sat' => $refunded,
                        ]);
                        try {
                            Mail::to($r->user->email)->queue(new WithdrawalRejectedEmail($r->fresh()));
                        } catch (\Throwable $e) {
                            // Status changes + ledger refund are already
                            // persisted; mail failure must not roll back
                            // money state. Log so ops can replay.
                            Log::warning('withdrawal rejected mail failed', [
                                'withdrawal_id' => $r->id,
                                'err' => $e->getMessage(),
                            ]);
                        }
                    }),
                Actions\EditAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWithdrawals::route('/'),
            'edit' => Pages\EditWithdrawal::route('/{record}/edit'),
        ];
    }
}
