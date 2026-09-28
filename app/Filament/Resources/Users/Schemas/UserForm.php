<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Аккаунт')
                    ->description('Основные данные для входа и роли')
                    ->schema([
                        TextInput::make('name')
                            ->label('Имя пользователя')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        TextInput::make('password')
                            ->label('Пароль')
                            ->password()
                            ->revealable()
                            ->rule(Password::default())
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->maxLength(255),

                        Select::make('roles')
                            ->label('Роли')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable(),
                    ])
                    ->columns(2),

                Section::make('Профиль')
                    ->description('Личные данные пользователя')
                    ->schema([
                        Select::make('country_id')
                            ->label('Страна')
                            ->relationship('country', 'iso2')
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->name ?? $record->iso2)
                            ->searchable(['iso2', 'iso3'])
                            ->preload(),

                        TextInput::make('first_name')
                            ->label('Имя'),

                        TextInput::make('last_name')
                            ->label('Фамилия'),

                        TextInput::make('birth_year')
                            ->label('Год рождения')
                            ->numeric(),
                        Select::make('direction')
                            ->label('Направление интерфейса')
                            ->options([
                                'ltr' => 'Слева направо (для правши)',
                                'rtl' => 'Справа налево (для левши)',
                            ])
                            ->default('ltr')
                            ->native(false)
                            ->required(),
                    ])
                    ->columns(2)
                    ->relationship('userData'),
            ]);
    }
}
