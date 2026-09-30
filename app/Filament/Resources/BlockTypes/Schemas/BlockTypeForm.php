<?php

namespace App\Filament\Resources\BlockTypes\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class BlockTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Код')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->helperText('Системное имя, например: text, header, image'),
                Select::make('type')
                    ->label('Тип')
                    ->options([
                        'info' => 'Информация',
                        'task' => 'Задача',
                    ])
                    ->default('info')
                    ->required()
                    ->native(false),
                KeyValue::make('default_settings')
                    ->label('Настройки по умолчанию')
                    ->keyLabel('Ключ')
                    ->valueLabel('Значение')
                    ->columnSpanFull()
                    ->helperText('JSON-настройки, например: {"align": "left"}'),

                Repeater::make('translations')
                    ->label('Локализации')
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

                        Textarea::make('description')
                            ->label('Описание')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->defaultItems(1)
                    ->addActionLabel('Добавить перевод')
                    ->columnSpanFull(),
            ]);
    }
}
