<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }



    /*public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }*/



public static function table(Table $table): Table
{
    return $table
        ->columns([
            TextColumn::make('id')
                ->sortable(),

            TextColumn::make('name')
                ->searchable() // Возможность поиска по имени
                ->sortable(),

            TextColumn::make('email')
                ->searchable()
                ->sortable(),
            TextColumn::make('roles.name')
                ->label('Роли')
                ->badge()
                ->separator(',')
                ->color('primary')
                ->placeholder('—'),
            TextColumn::make('created_at')
                ->dateTime('d.m.Y H:i')
                ->sortable()
                ->label('Дата регистрации'),
        ])
        ->defaultSort('created_at', 'desc') // Сортировка по умолчанию
        ->filters([
            // Здесь можно добавить фильтры позже
        ])
        ->actions([
            // Действия для каждой строки (Edit и Delete уже добавлены по умолчанию)
        ]);
}

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
