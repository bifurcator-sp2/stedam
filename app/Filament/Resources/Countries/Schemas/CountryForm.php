<?php

namespace App\Filament\Resources\Countries\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CountryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('iso2')
                    ->label('ISO2')
                    ->required()
                    ->maxLength(2)
                    ->unique(ignoreRecord: true)
                    ->helperText('Например: RU, US, DE'),

                TextInput::make('iso3')
                    ->label('ISO3')
                    ->maxLength(3)
                    ->helperText('Например: RUS, USA, DEU'),

                TextInput::make('phone_code')
                    ->label('Телефонный код')
                    ->maxLength(10)
                    ->helperText('Например: +7, +1'),

                Toggle::make('is_active')
                    ->label('Активна')
                    ->default(true),

                Repeater::make('translations')
                    ->label('Переводы')
                    ->relationship()
                    ->schema([
                        TextInput::make('locale')
                            ->label('Язык')
                            ->required()
                            ->default('ru')
                            ->maxLength(10),

                        TextInput::make('name')
                            ->label('Название')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->columns(2)
                    ->defaultItems(2)
                    ->addActionLabel('Добавить перевод')
                    ->columnSpanFull(),
            ]);
    }
}
