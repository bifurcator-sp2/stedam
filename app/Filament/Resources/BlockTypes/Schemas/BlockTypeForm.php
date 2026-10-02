<?php

namespace App\Filament\Resources\BlockTypes\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
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

                Textarea::make('default_settings')
                    ->label('Настройки по умолчанию')
                    ->formatStateUsing(fn ($state) => is_array($state)
                        ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                        : ($state ?? '{}')
                    )
                    ->dehydrateStateUsing(fn ($state) => is_string($state)
                        ? (json_decode($state, true) ?: [])
                        : ($state ?? [])
                    )
                    ->rows(15)
                    ->columnSpanFull()
                    ->helperText(fn (Get $get) => self::helperTextForCode($get('code')))
                    ->rule('json'),

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

    /**
     * Подсказка под полем — какие ключи доступны для этого code.
     */
    private static function helperTextForCode(?string $code): ?string
    {
        if (! $code) {
            return 'Сначала укажите код — доступные ключи появятся здесь.';
        }

        if (! \App\Blocks\Settings\BlockSettingsRegistry::has($code)) {
            return "Для кода «{$code}» настройки не описаны в реестре. Сохранится как есть.";
        }

        $keys = array_map(
            fn ($def) => $def->key,
            \App\Blocks\Settings\BlockSettingsRegistry::for($code),
        );

        return 'Доступные ключи: ' . implode(', ', $keys);
    }
}
