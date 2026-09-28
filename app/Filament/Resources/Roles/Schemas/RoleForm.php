<?php

namespace App\Filament\Resources\Roles\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),

                // guard_name обязателен для Spatie, но менять его в UI обычно не нужно.
                // Оставляем hidden, чтобы модель получила значение по умолчанию 'web'.
                TextInput::make('guard_name')
                    ->default('web')
                    ->hidden(),
                Repeater::make('translations')
                    ->label('Локализации')
                    ->relationship()
                    ->schema([
                        TextInput::make('locale')
                            ->label('Язык')
                            ->required()
                            ->default('ru')
                            ->maxLength(10),

                        TextInput::make('label')
                            ->label('Название для пользователя')
                            ->required()
                            ->maxLength(255),

                        Textarea::make('description')
                            ->label('Описание')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->defaultItems(1)
                    ->addActionLabel('Добавить язык')
                    ->columnSpanFull(),
            ]);
    }
}
