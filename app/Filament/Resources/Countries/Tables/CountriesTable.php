<?php

namespace App\Filament\Resources\Countries\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class CountriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('iso2')
                    ->label('ISO2')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Название')
                    ->state(fn ($record) => $record->name)
                    ->searchable(query: function ($query, string $search) {
                        $query->whereHas('translations', fn ($q) => $q->where('name', 'like', "%{$search}%"));
                    }),

                TextColumn::make('iso3')
                    ->label('ISO3')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('phone_code')
                    ->label('Код')
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Активна')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
                SelectFilter::make('locale')
                    ->label('Язык')
                    ->options([
                        'ru' => 'Русский',
                        'en' => 'English',
                    ])
                    ->query(function ($query, array $data) {
                        if (! empty($data['value'])) {
                            $query->whereHas('translations', fn ($q) => $q->where('locale', $data['value']));
                        }
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('iso2');
    }
}
