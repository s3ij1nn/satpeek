<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PtcAdResource\Pages;
use App\Mail\AdApprovedEmail;
use App\Mail\AdRejectedEmail;
use App\Models\BalanceLedger;
use App\Models\PtcAd;
use App\Models\PtcView;
use App\Services\AdminAuditor;
use App\Services\IframeEmbedProbe;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
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
use Illuminate\Support\Str;
use UnitEnum;

class PtcAdResource extends Resource
{
    protected static ?string $model = PtcAd::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'PTC ads';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Schemas\Components\Section::make('Ad')->schema([
                Forms\Components\TextInput::make('title')
                    ->required()
                    ->maxLength(200),
                Forms\Components\Textarea::make('description')
                    ->rows(3)
                    ->maxLength(500),
                Forms\Components\TextInput::make('target_url')
                    ->label('Target URL')
                    ->url()
                    ->required()
                    ->maxLength(500),
                Forms\Components\Select::make('display_mode')
                    ->label('Display mode')
                    ->options([
                        'window' => 'New tab — opens target in a separate window (works for sites that block iframes / top-redirect)',
                        'iframe' => 'Inline iframe — embeds the target inside the viewer page (only works if the site allows framing)',
                    ])
                    ->default('window')
                    ->required()
                    ->helperText('Pick "iframe" only when you control the destination and have verified it sets no X-Frame-Options/CSP frame-ancestors restriction.'),
            ])->columns(1),

            Schemas\Components\Section::make('Reward & timing')->schema([
                Forms\Components\TextInput::make('reward_sat')
                    ->label('Reward (sat)')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->default(5),
                Forms\Components\TextInput::make('duration_sec')
                    ->label('View duration (seconds)')
                    ->numeric()
                    ->minValue(5)
                    ->maxValue(300)
                    ->required()
                    ->default(15),
                Forms\Components\TextInput::make('daily_limit_per_user')
                    ->label('Daily limit per user')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->default(3),
            ])->columns(3),

            Schemas\Components\Section::make('Source & status')->schema([
                Forms\Components\Select::make('source')
                    ->options([
                        'internal' => 'Internal (own inventory)',
                        'mock' => 'Mock (development)',
                        'bitcotask' => 'BitcoTask',
                    ])
                    ->default('internal')
                    ->required(),
                Forms\Components\TextInput::make('external_id')
                    ->helperText('Auto-generated for internal ads.')
                    ->default(fn () => 'internal-'.Str::lower(Str::random(8))),
                Forms\Components\Toggle::make('is_active')
                    ->default(true),
                Forms\Components\DateTimePicker::make('expires_at')
                    ->helperText('Leave blank to run indefinitely.'),
            ])->columns(2),
        ]);
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = PtcAd::where('status', 'pending_review')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('source')
                    ->badge()
                    ->colors([
                        'success' => 'internal',
                        'gray' => 'mock',
                        'warning' => 'bitcotask',
                        'info' => 'user',
                    ]),
                Tables\Columns\TextColumn::make('advertiser.username')
                    ->label('Advertiser')
                    ->placeholder('— admin —')
                    ->url(fn ($record) => $record->user_id ? UserResource::getUrl('edit', ['record' => $record->user_id]) : null)
                    ->searchable(),
                Tables\Columns\TextColumn::make('title')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'success' => 'approved',
                        'warning' => 'pending_review',
                        'info' => 'completed',
                        'danger' => 'rejected',
                        'gray' => fn ($state) => in_array($state, ['draft', 'paused'], true),
                    ])
                    ->sortable(),
                Tables\Columns\TextColumn::make('display_mode')
                    ->label('Display')
                    ->badge()
                    ->colors(['gray' => 'iframe', 'success' => 'window'])
                    ->toggleable(),
                Tables\Columns\TextColumn::make('reward_sat')->label('Reward')->suffix(' sat')->sortable(),
                Tables\Columns\TextColumn::make('cost_per_view_sat')->label('Cost/view')->suffix(' sat')->toggleable(),
                Tables\Columns\TextColumn::make('views_remaining')
                    ->label('Budget')
                    ->getStateUsing(function ($record) {
                        if ($record->user_id === null) {
                            return '∞';
                        }

                        return number_format($record->views_remaining).' / '.number_format($record->total_views_purchased);
                    })
                    ->toggleable(),
                Tables\Columns\TextColumn::make('duration_sec')->label('Duration')->suffix(' s')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('daily_limit_per_user')->label('Daily/user')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('verified_views')
                    ->label('Verified')
                    ->getStateUsing(fn ($record) => PtcView::where('ptc_ad_id', $record->id)->where('status', 'verified')->count())
                    ->numeric()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('attempts')
                    ->label('Attempts')
                    ->getStateUsing(fn ($record) => PtcView::where('ptc_ad_id', $record->id)->count())
                    ->numeric()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(array_combine(PtcAd::STATUSES, PtcAd::STATUSES)),
                Tables\Filters\SelectFilter::make('source')
                    ->options([
                        'internal' => 'Internal (admin)',
                        'user' => 'User-submitted',
                        'mock' => 'Mock',
                        'bitcotask' => 'BitcoTask',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active'),
            ])
            ->actions([
                // Approve a pending submission — flips status + activates serving.
                Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (PtcAd $r) => $r->status === 'pending_review')
                    ->action(function (PtcAd $r) {
                        $r->update([
                            'status' => 'approved',
                            'is_active' => true,
                            'approved_at' => Carbon::now(),
                            'reviewed_by' => auth()->id(),
                        ]);
                        AdminAuditor::record('ptc_ad.approve', $r);
                        if ($r->user_id) {
                            try {
                                Mail::to($r->advertiser->email)->queue(new AdApprovedEmail($r->fresh()));
                            } catch (\Throwable $e) {
                            }
                        }
                    }),
                // Reject a pending submission — refunds the full reserved budget.
                Actions\Action::make('reject')
                    ->label('Reject & refund')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (PtcAd $r) => in_array($r->status, ['pending_review', 'approved'], true) && $r->user_id !== null)
                    ->schema([
                        Forms\Components\Textarea::make('rejection_reason')
                            ->label('Reason (visible to advertiser)')
                            ->required(),
                    ])
                    ->action(function (PtcAd $r, array $data) {
                        // Re-fetch with row-level lock + atomic status
                        // guard inside the transaction. The Eloquent
                        // instance loaded at render time has a stale
                        // `views_remaining` snapshot — between page load
                        // and this action, the EarnSessionClaimService
                        // postCredit hook can have decremented the
                        // counter via concurrent PTC views. Computing
                        // the refund from the stale snapshot would
                        // over-refund sat already spent serving views.
                        // The lockForUpdate forces every concurrent
                        // PTC view to wait until our transaction commits.
                        $result = DB::transaction(function () use ($r, $data): array {
                            /** @var PtcAd|null $fresh */
                            $fresh = PtcAd::lockForUpdate()->find($r->id);
                            if (! $fresh || ! in_array($fresh->status, ['pending_review', 'approved'], true)) {
                                return ['settled' => false, 'refund' => 0];
                            }
                            $refund = (int) ($fresh->views_remaining * $fresh->cost_per_view_sat);
                            if ($refund > 0) {
                                BalanceLedger::create([
                                    'user_id' => $fresh->user_id,
                                    'delta_sat' => $refund,
                                    'reason' => BalanceLedger::REASON_AD_REFUND,
                                    'reference_type' => PtcAd::class,
                                    'reference_id' => $fresh->id,
                                ]);
                                $fresh->advertiser->increment('balance_sat', $refund);
                            }
                            $fresh->update([
                                'status' => 'rejected',
                                'is_active' => false,
                                'rejection_reason' => $data['rejection_reason'],
                                'reviewed_by' => Auth::id(),
                                'views_remaining' => 0,
                            ]);

                            return ['settled' => true, 'refund' => $refund];
                        });
                        if (! $result['settled']) {
                            return; // raced — another admin / status flip won
                        }
                        AdminAuditor::record('ptc_ad.reject', $r, [
                            'rejection_reason' => $data['rejection_reason'],
                            'refunded_sat' => $result['refund'],
                        ]);
                        try {
                            Mail::to($r->advertiser->email)->queue(new AdRejectedEmail($r->fresh()));
                        } catch (\Throwable $e) {
                            Log::warning('ad rejected mail failed', [
                                'ad_id' => $r->id,
                                'err' => $e->getMessage(),
                            ]);
                        }
                    }),
                // Operator-side iframe preflight. Mirrors the advertiser-
                // facing probe in AdvertiseController but lives on the
                // table so the operator can spot-check an existing ad
                // (e.g. when an advertiser complains "my campaign isn't
                // showing"). Only surfaces for iframe-mode rows because
                // window-mode embedding is unconditional.
                Actions\Action::make('test_iframe')
                    ->label('Test embed')
                    ->icon('heroicon-o-window')
                    ->color('warning')
                    ->visible(fn (PtcAd $r) => $r->display_mode === 'iframe' && filled($r->target_url))
                    ->action(function (PtcAd $r): void {
                        try {
                            $verdict = app(IframeEmbedProbe::class)->probe((string) $r->target_url);
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Probe error')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        if ($verdict['embeddable']) {
                            Notification::make()
                                ->title('Embeddable')
                                ->body($verdict['blocker'] === 'probe_failed'
                                    ? ($verdict['detail'] ?? 'Probe inconclusive — defaulting to embeddable.')
                                    : 'No iframe-blocking headers detected.')
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Not embeddable')
                                ->body($verdict['detail'] ?? $verdict['blocker'] ?? 'unknown')
                                ->danger()
                                ->send();
                        }
                    }),
                Actions\EditAction::make(),
                Actions\DeleteAction::make()
                    ->visible(fn (PtcAd $r) => $r->user_id === null),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPtcAds::route('/'),
            'create' => Pages\CreatePtcAd::route('/create'),
            'edit' => Pages\EditPtcAd::route('/{record}/edit'),
        ];
    }
}
