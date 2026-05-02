<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InternalArticleResource\Pages;
use App\Models\InternalArticle;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Operator CRUD for the internal "read & earn" article inventory.
 *
 * Articles are rendered inline at /read-articles/internal/{token} so
 * we can verify the user actually sat on our page for read_seconds
 * before unlocking the captcha. Body is plain Markdown; the public
 * view runs it through league/commonmark with `html_input=>strip` so
 * a hostile body can't XSS readers.
 */
class InternalArticleResource extends Resource
{
    protected static ?string $model = InternalArticle::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-newspaper';

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Read articles';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Schemas\Components\Section::make('Article')->schema([
                Forms\Components\TextInput::make('title')
                    ->required()
                    ->maxLength(200)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('source_attribution')
                    ->maxLength(200)
                    ->helperText('Optional credit line shown under the title (e.g. publisher / author).')
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('body')
                    ->required()
                    ->rows(18)
                    ->helperText('Markdown. Headings (#, ##, ###), lists, **bold**, *italic*, [links](url), `code`, > blockquotes, and code fences. Raw HTML is stripped at render time.')
                    ->columnSpanFull(),
            ]),
            Schemas\Components\Section::make('Per-view economics')->schema([
                Forms\Components\TextInput::make('reward_sat')
                    ->label('Reward per completed read (sat)')
                    ->numeric()
                    ->required()
                    ->default(10)
                    ->minValue(1)
                    ->helperText('Paid only AFTER the user has spent the full read_seconds on the article AND passed the captcha. Opening the article alone yields nothing.'),
                Forms\Components\TextInput::make('read_seconds')
                    ->label('Read time (seconds)')
                    ->numeric()
                    ->required()
                    ->default(45)
                    ->minValue(10)
                    ->maxValue(600)
                    ->helperText('Countdown shown on the reader page; the claim button unlocks when it hits zero. Server-side floor matches.'),
                Forms\Components\TextInput::make('daily_limit_per_user')
                    ->label('Daily limit (per user)')
                    ->numeric()
                    ->required()
                    ->default(3)
                    ->minValue(1),
                Forms\Components\Toggle::make('is_active')
                    ->default(true)
                    ->helperText('Inactive articles are hidden from /read-articles even if a user has remaining views today.'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('title')->searchable()->limit(50),
                Tables\Columns\TextColumn::make('source_attribution')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('reward_sat')->suffix(' sat')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('read_seconds')->suffix(' s')->numeric()->toggleable(),
                Tables\Columns\TextColumn::make('daily_limit_per_user')->label('Daily/user')->numeric()->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('updated_at')->since()->sortable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
            ])
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInternalArticles::route('/'),
            'create' => Pages\CreateInternalArticle::route('/create'),
            'edit' => Pages\EditInternalArticle::route('/{record}/edit'),
        ];
    }
}
