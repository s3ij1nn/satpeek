<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShortlinkClickResource\Pages;
use App\Models\ShortlinkClick;
use BackedEnum;
use Filament\Actions;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Operator debug surface for in-flight + recent shortlink clicks.
 *
 * Mirror of PtcViewResource. Read-only by design — touching a row's
 * status would side-step the hold + captcha guards. Use the row actions
 * to copy the epoch_token or open the user-facing
 * /shortlinks/auth/{token} URL when triaging a missing-reward complaint.
 */
class ShortlinkClickResource extends Resource
{
    protected static ?string $model = ShortlinkClick::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cursor-arrow-rays';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Shortlink clicks';

    protected static ?int $navigationSort = 26;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('user.username')
                    ->label('Clicker')
                    ->searchable()
                    ->url(fn (ShortlinkClick $r) => $r->user_id ? UserResource::getUrl('edit', ['record' => $r->user_id]) : null),
                Tables\Columns\TextColumn::make('shortlink.title')
                    ->label('Shortlink')
                    ->searchable()
                    ->wrap()
                    ->url(fn (ShortlinkClick $r) => $r->shortlink_id ? ShortlinkResource::getUrl('edit', ['record' => $r->shortlink_id]) : null),
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
                Actions\Action::make('open_auth_url')
                    ->label('Auth URL')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('info')
                    ->url(fn (ShortlinkClick $r) => route('shortlinks.auth', ['token' => $r->epoch_token]))
                    ->openUrlInNewTab()
                    ->visible(fn (ShortlinkClick $r) => $r->status === 'pending'),
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
            'index' => Pages\ListShortlinkClicks::route('/'),
        ];
    }
}
