<?php

namespace App\Filament\Resources\Roles\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Имя')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('guard_name')
                    ->label('Guard')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('translations.label')
                    ->label('Название')
                    ->badge()
                    ->separator(',')
                    ->placeholder('—'),

                TextColumn::make('translations.description')
                    ->label('Описание')
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('users_count')
                    ->counts('users')
                    ->label('Пользователей')
                    ->sortable(),

                TextColumn::make('permissions_count')
                    ->counts('permissions')
                    ->label('Разрешений')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
