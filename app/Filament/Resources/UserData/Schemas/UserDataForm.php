<?php

namespace App\Filament\Resources\UserData\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserDataForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->label('Пользователь')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->helperText('Один пользователь может иметь только одну запись профиля.'),

                TextInput::make('last_name')
                    ->label('Фамилия')
                    ->maxLength(255),

                TextInput::make('first_name')
                    ->label('Имя')
                    ->maxLength(255),

                TextInput::make('middle_name')
                    ->label('Отчество')
                    ->maxLength(255),

                TextInput::make('birth_year')
                    ->label('Год рождения')
                    ->numeric()
                    ->minValue(1900)
                    ->maxValue((int) date('Y'))
                    ->rule('integer'),

                Select::make('gender')
                    ->label('Пол')
                    ->options([
                        'male' => 'Мужской',
                        'female' => 'Женский',
                    ])
                    ->native(false),

                Select::make('country_id')
                    ->label('Страна')
                    ->relationship(
                        name: 'country',
                        titleAttribute: 'iso2',
                        modifyQueryUsing: fn ($query) => $query->with('translations'),
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn ($record) => $record->name ?? $record->iso2
                    )
                    ->searchable(['iso2', 'iso3'])
                    ->preload()
                    ->native(false),
                Select::make('direction')
                    ->label('Направление интерфейса')
                    ->options([
                        'ltr' => 'Слева направо (для правши)',
                        'rtl' => 'Справа налево (для левши)',
                    ])
                    ->default('ltr')
                    ->required(),
            ]);
    }
}
