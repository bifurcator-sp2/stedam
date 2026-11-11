<?php

namespace App\Filament\Resources\Taxonomies\Schemas;

use App\Infrastructure\Blocks\TestGenerator\Support\GeneratorOptions;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Форма узла таксономии.
 *
 * Две секции:
 *
 *  1. Переводы — Repeater по translations (locale/title/description),
 *     как было.
 *
 *  2. Генератор заданий — Select с сгруппированными опциями от
 *     GeneratorOptions. Ключ 'test_resolver' хранится на узле и
 *     используется TestResolverMap для резолва «узел → topic».
 *
 * Семантика поля test_resolver:
 *   — пусто на узле → узел наследует генератор от ближайшего предка
 *     с заданным test_resolver (префиксный алгоритм TestResolverMap);
 *   — задан на узле → узел и все его потомки (без своего значения)
 *     используют именно этот генератор;
 *   — задан на листе → точечный override конкретной ветки.
 *
 * Поэтому поле показывается на ЛЮБОМ узле, а не только на листьях.
 */
class TaxonomyForm
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
                        TextInput::make('title')
                            ->label('Название')
                            ->required(),
                        Textarea::make('description')
                            ->label('Описание')
                            ->nullable(),
                    ])
                    ->columns(3)
                    ->defaultItems(1)
                    ->addActionLabel('Добавить перевод'),

                Section::make('Генератор заданий')
                    ->description(
                        'Привязка к узлу дерева.'
                    )
                    ->collapsible()
                    ->collapsed(false)
                    ->schema([
                        Select::make('test_resolver')
                            ->label('Генератор')
                            ->options(fn (): array => app(GeneratorOptions::class)->grouped())
                            ->searchable()
                            ->native(false)
                            ->placeholder('— не задан (наследуется) —')
                            ->helperText(
                                'Например, Math.Algebra соответствует файлу '
                                . 'app/Blocks/TestGenerator/Generators/Math/AlgebraGenerator.php'
                            ),
                    ]),
            ]);
    }
}
