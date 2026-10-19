<?php

namespace App\Filament\Resources\BlockIndexes\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class BlockIndexForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Repeater::make('translations')
                    ->relationship('translations')
                    ->schema([
                        Select::make('locale')
                            ->options(['en' => 'English', 'ru' => 'Русский'])
                            ->default(fn () => app()->getLocale())
                            ->required(),
                        TextInput::make('title')->label('Название')->required(),
                        Textarea::make('description')->label('Описание')->nullable(),
                    ])
                    ->columns(3)
                    ->defaultItems(1)
                    ->addActionLabel('Добавить перевод'),
            ]);
    }
}
