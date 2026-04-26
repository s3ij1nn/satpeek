<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

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
