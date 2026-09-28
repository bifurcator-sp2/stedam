<?php

namespace App\Filament\Resources\UserData\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UserDataTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Пользователь')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('last_name')
                    ->label('Фамилия')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('first_name')
                    ->label('Имя')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('middle_name')
                    ->label('Отчество')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('birth_year')
                    ->label('Год рождения')
                    ->sortable(),

                TextColumn::make('gender')
                    ->label('Пол')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'male' => 'Мужской',
                        'female' => 'Женский',
                        default => '—',
                    })
                    ->color(fn (?string $state) => match ($state) {
                        'male' => 'info',
                        'female' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('country.name')
                    ->label('Страна')
                    ->getStateUsing(fn ($record) => $record->country?->name)
                    ->placeholder('—'),

                TextColumn::make('direction')
                    ->label('Направление')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'ltr' => 'Правша',
                        'rtl' => 'Левша',
                        default => '—',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('gender')
                    ->label('Пол')
                    ->options([
                        'male' => 'Мужской',
                        'female' => 'Женский',
                    ]),

                SelectFilter::make('country_id')
                    ->label('Страна')
                    ->relationship('country', 'iso2')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name ?? $record->iso2)
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
