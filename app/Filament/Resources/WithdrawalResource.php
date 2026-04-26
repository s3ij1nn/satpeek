<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WithdrawalResource\Pages;
use App\Mail\WithdrawalRejectedEmail;
use App\Models\BalanceLedger;
use App\Models\Withdrawal;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class WithdrawalResource extends Resource
{
    protected static ?string $model = Withdrawal::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Operations';

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

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Withdrawal')->schema([
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
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('user.email')->label('User')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('amount_sat')->label('Amount')->suffix(' sat')->sortable()->numeric(),
                Tables\Columns\TextColumn::make('currency')->badge(),
                Tables\Columns\TextColumn::make('faucetpay_email')->label('Pay to')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('status')->badge()->colors([
                    'gray' => 'queued',
                    'warning' => 'hold',
                    'info' => 'processing',
                    'success' => 'sent',
                    'danger' => fn ($state) => in_array($state, ['failed', 'rejected'], true),
                ])->sortable(),
                Tables\Columns\IconColumn::make('requires_review')->boolean(),
                Tables\Columns\TextColumn::make('faucetpay_payout_id')->label('Payout id')->placeholder('—')->copyable(),
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
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Withdrawal $r) => $r->status === 'hold')
                    ->action(function (Withdrawal $r) {
                        $r->update([
                            'status' => 'queued',
                            'requires_review' => false,
                            'reviewed_by' => auth()->id(),
                        ]);
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Reject & refund')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Withdrawal $r) => in_array($r->status, ['hold', 'queued'], true))
                    ->form([
                        Forms\Components\Textarea::make('failure_reason')
                            ->label('Reason (visible to user)')
                            ->required(),
                    ])
                    ->action(function (Withdrawal $r, array $data) {
                        DB::transaction(function () use ($r, $data) {
                            BalanceLedger::create([
                                'user_id' => $r->user_id,
                                'delta_sat' => (int) $r->amount_sat,
                                'reason' => 'withdraw_rejected',
                                'reference_type' => Withdrawal::class,
                                'reference_id' => $r->id,
                            ]);
                            $r->user->increment('balance_sat', (int) $r->amount_sat);
                            $r->update([
                                'status' => 'rejected',
                                'requires_review' => false,
                                'reviewed_by' => auth()->id(),
                                'failure_reason' => $data['failure_reason'],
                                'processed_at' => Carbon::now(),
                            ]);
                        });
                        try {
                            Mail::to($r->user->email)->queue(new WithdrawalRejectedEmail($r->fresh()));
                        } catch (\Throwable $e) {
                            // best-effort, status changes already persisted
                        }
                    }),
                Tables\Actions\EditAction::make(),
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
