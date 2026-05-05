<?php

namespace App\Filament\Resources;

use App\Enums\EarnSessionStatus;
use App\Filament\Resources\InternalArticleViewResource\Pages;
use App\Models\InternalArticleView;
use BackedEnum;
use Filament\Actions;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Operator debug surface for in-flight + recent internal-article views.
 *
 * Mirror of PtcViewResource / ShortlinkClickResource. Read-only by
 * design — touching a row's status would side-step the read-time +
 * captcha guards. Use the row action to copy the epoch_token or open
 * the user-facing /read-articles/internal/{token} URL when triaging
 * a missing-reward complaint.
 */
class InternalArticleViewResource extends Resource
{
    protected static ?string $model = InternalArticleView::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Article reads';

    protected static ?int $navigationSort = 28;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('user.username')
                    ->label('Reader')
                    ->searchable()
                    ->url(fn (InternalArticleView $r) => $r->user_id ? UserResource::getUrl('edit', ['record' => $r->user_id]) : null),
                Tables\Columns\TextColumn::make('article.title')
                    ->label('Article')
                    ->limit(40)
                    ->searchable(),
                Tables\Columns\TextColumn::make('reward_sat')->suffix(' sat')->numeric()->toggleable(),
                Tables\Columns\TextColumn::make('read_seconds')->suffix(' s')->numeric()->toggleable(),
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
                Actions\Action::make('open_read_url')
                    ->label('Reader URL')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('info')
                    ->url(fn (InternalArticleView $r) => route('internal_articles.read', ['token' => $r->epoch_token]))
                    ->openUrlInNewTab()
                    ->visible(fn (InternalArticleView $r) => $r->status === EarnSessionStatus::Pending),
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
            'index' => Pages\ListInternalArticleViews::route('/'),
        ];
    }
}
