<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PtcViewResource\Pages;
use App\Models\PtcView;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Operator debug surface for in-flight + recent PTC watch sessions.
 *
 * Read-only by design — sessions are owned by users and any mutation here
 * (especially flipping a `pending` row to `verified`) would side-step the
 * captcha + heartbeat + duration guards. Use the row actions to copy the
 * epoch_token or open the user-facing /ptc/auth/{token} URL when triaging
 * a "my reward didn't arrive" complaint.
 */
class PtcViewResource extends Resource
{
    protected static ?string $model = PtcView::class;

    protected static ?string $navigationIcon = 'heroicon-o-eye';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'PTC views';

    protected static ?int $navigationSort = 25;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('user.username')
                    ->label('Viewer')
                    ->searchable()
                    ->url(fn (PtcView $r) => $r->user_id ? UserResource::getUrl('edit', ['record' => $r->user_id]) : null),
                Tables\Columns\TextColumn::make('ad.title')
                    ->label('Ad')
                    ->searchable()
                    ->wrap()
                    ->url(fn (PtcView $r) => $r->ptc_ad_id ? PtcAdResource::getUrl('edit', ['record' => $r->ptc_ad_id]) : null),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'verified',
                        'danger' => 'rejected',
                        'gray' => 'expired',
                    ])
                    ->sortable(),
                Tables\Columns\TextColumn::make('rejection_reason')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('heartbeats')
                    ->label('Heartbeats')
                    ->getStateUsing(fn (PtcView $r) => $r->heartbeats_received.' / '.$r->heartbeats_expected),
                Tables\Columns\TextColumn::make('started_at')->since()->sortable(),
                Tables\Columns\TextColumn::make('completed_at')->since()->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('epoch_token')
                    ->label('Token')
                    ->limit(14)
                    ->copyable()
                    ->copyMessage('Copied auth token')
                    ->fontFamily('mono')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'pending',
                        'verified' => 'verified',
                        'rejected' => 'rejected',
                        'expired' => 'expired',
                    ]),
            ])
            ->actions([
                // Open the user-facing auth URL — useful when an operator
                // is on a triage call and needs to land on the same page
                // the user is looking at. Only meaningful while pending;
                // hidden once the click has been resolved (the page would
                // 410 anyway).
                Tables\Actions\Action::make('open_auth_url')
                    ->label('Auth URL')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('info')
                    ->url(fn (PtcView $r) => route('ptc.auth', ['token' => $r->epoch_token]))
                    ->openUrlInNewTab()
                    ->visible(fn (PtcView $r) => $r->status === 'pending'),
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
            'index' => Pages\ListPtcViews::route('/'),
        ];
    }
}
