<?php

namespace App\Blocks\Settings;

use App\Enums\FilePurpose;

class BlockSettingsRegistry
{
    public static function getBordersDefinition($key = 'border', $label = 'Границы'): SettingDefinition
    {
        return new SettingDefinition(
            key: $key,
            label: $label,
            type: SettingType::Array,
            children: [
            new SettingDefinition(
                key: 'color',
                type: SettingType::Color,
                default: 'border-default',
                allowed: ColorPalette::borderTokens(),
                label: 'Цвет',
            ),
            new SettingDefinition(
                key: 'left',
                type: SettingType::Int,
                default: 0,
                label: 'Слева',
            ),
            new SettingDefinition(
                key: 'right',
                type: SettingType::Int,
                default: 0,
                label: 'Справа',
            ),
            new SettingDefinition(
                key: 'top',
                type: SettingType::Int,
                default: 0,
                label: 'Сверху',
            ),
            new SettingDefinition(
                key: 'bottom',
                type: SettingType::Int,
                default: 0,
                label: 'Снизу',
            ),
            new SettingDefinition(
                key: 'radius',
                label: 'Радиус',
                type: SettingType::Array,
                children: [
                new SettingDefinition(
                    key: 'top-left',
                    type: SettingType::Int,
                    default: 0,
                    label: 'Левый верхний',
                ),
                new SettingDefinition(
                    key: 'top-right',
                    type: SettingType::Int,
                    default: 0,
                    label: 'Правый верхний',
                ),
                new SettingDefinition(
                    key: 'bottom-right',
                    type: SettingType::Int,
                    default: 0,
                    label: 'Нижний правый',
                ),
                new SettingDefinition(
                    key: 'bottom-left',
                    type: SettingType::Int,
                    default: 0,
                    label: 'Нижний левый',
                ),
            ],
            ),
        ],
        );
    }

    public static function getPaddingsDefinition()
    {
        return new SettingDefinition(
            key: 'padding',
            label: 'Padding',
            type: SettingType::Array,
            children: [
            new SettingDefinition(
                key: 'left',
                type: SettingType::Int,
                default: 0,
                label: 'Слева',
            ),
            new SettingDefinition(
                key: 'right',
                type: SettingType::Int,
                default: 0,
                label: 'Справа',
            ),
            new SettingDefinition(
                key: 'top',
                type: SettingType::Int,
                default: 0,
                label: 'Сверху',
            ),
            new SettingDefinition(
                key: 'bottom',
                type: SettingType::Int,
                default: 0,
                label: 'Снизу',
            ),
        ],
        );
    }

    public static function getTextSettingsDefinition()
    {
        return new SettingDefinition(
            key: 'settings',
            label: 'Настройки текста',
            type: SettingType::Array,
            children: [
                new SettingDefinition(
                    key: 'text-block-align',
                    type: SettingType::Select,
                    default: 'left',
                    allowed: [
                    'left', 'right', 'center', 'justify', 'start', 'end', 'match-parent', 'justify-all',
                ],
                    label: 'Выравнивание текста',
                ),
                new SettingDefinition(
                    key: 'text-size',
                    type: SettingType::Select,
                    default: 'text-base',
                    allowed: [
                    'text-xs',
                    'text-sm',
                    'text-base',
                    'text-lg',
                    'text-xl',
                    'text-2xl',
                    'text-3xl',
                    'text-4xl',
                    'text-5xl',
                    'text-6xl',
                    'text-7xl',
                    'text-8xl',
                    'text-9xl',
                ],
                    label: 'Размер шрифта',
                ),
                new SettingDefinition(
                    key: 'container',
                    type: SettingType::Select,
                    default: 'div',
                    allowed: ['div', 'ProseP', 'ProseStrong', 'ProseEm', 'ProseBlockquote', 'ProseH1', 'ProseH2', 'ProseH3', 'ProseH4', 'ProseH5', 'ProseH6', 'ProseCode', 'ProsePre'],
                    label: 'Контейнер',
                ),
                new SettingDefinition(
                    key: 'text-cols',
                    type: SettingType::Int,
                    default: 1,
                    label: 'Количество колонок текста',
                ),
            ]
        );
    }

    public static function getMarginDefinition()
    {
        return new SettingDefinition(
            key: 'margin',
            label: 'Margin',
            type: SettingType::Array,
            children: [
            new SettingDefinition(
                key: 'left',
                type: SettingType::Int,
                default: 0,
                label: 'Слева',
            ),
            new SettingDefinition(
                key: 'right',
                type: SettingType::Int,
                default: 0,
                label: 'Справа',
            ),
            new SettingDefinition(
                key: 'top',
                type: SettingType::Int,
                default: 0,
                label: 'Сверху',
            ),
            new SettingDefinition(
                key: 'bottom',
                type: SettingType::Int,
                default: 0,
                label: 'Снизу',
            ),
        ],
        );
    }

    public static function getTypeGalleryDefinition($key = 'images', $label = 'Изображения', $imagesPurpose = FilePurpose::Gallery1)
    {
        return new SettingDefinition(
            key: $key,
            type: SettingType::Array,
            label: $label,
            default: [],
            children: [
            new SettingDefinition(
                key: 'images-block-position',
                type: SettingType::Select,
                default: 'center',
                allowed: [
                'flex-start',
                'center',
                'flex-end',
            ],
                label: 'Позиция блока изображений',
            ),
            new SettingDefinition(
                key: 'count',
                type: SettingType::Int,
                default: 1,
                label: 'Количество изображений',
            ),
            new SettingDefinition(
                key: $imagesPurpose->value,
                public: false,
                type: SettingType::Image,
                default: [],
                params: ['purpose' => $imagesPurpose->value, 'count' => 'parent.count', 'display-images' => false],
                label: 'Галерея',
                children: [
                new SettingDefinition(
                    key: 'columns',
                    type: SettingType::Int,
                    default: 1,
                    label: 'Количество колонок',
                ),
                new SettingDefinition(
                    key: 'distance',
                    type: SettingType::Int,
                    default: 0,
                    label: 'Расстояние',
                ),
                new SettingDefinition(
                    key: 'background-color',
                    type: SettingType::Color,
                    default: 'bg-default',
                    allowed: ColorPalette::bgTokens(),
                    label: 'Цвет фона',
                ),
                new SettingDefinition(
                    key: 'flex-direction',
                    type: SettingType::Select,
                    default: 'column',
                    allowed: ['column', 'row', 'masonry'],
                    label: 'Расположение изображений',
                ),
                new SettingDefinition(
                    key: 'image-size-strategy',
                    type: SettingType::Select,
                    default: 'fill',
                    allowed: ['fill', 'contain', 'cover', 'none', 'scale-down'],
                    label: 'Масштабирование изображений',
                ),
                new SettingDefinition(
                    key: 'image-height',
                    type: SettingType::Int,
                    default: 200,
                    label: 'Высота ряда',
                ),
                new SettingDefinition(
                    key: 'image-outline',
                    type: SettingType::Int,
                    default: 0,
                    label: 'Ширина линии',
                ),
                new SettingDefinition(
                    key: 'image-outline-color',
                    type: SettingType::Color,
                    default: 'border-default',
                    allowed: ColorPalette::borderTokens(),
                    label: 'Цвет линии',
                ),
                self::getBordersDefinition('image-border', 'Рамки изображений'),
                self::getPaddingsDefinition(),
                self::getMarginDefinition(),
                self::getBordersDefinition(),
            ],
            ),
        ],
        );
    }

    public static function getTypeHtmltextDefinition($key = 'htmltext', $label = 'Текст')
    {
        return new SettingDefinition(
            key: $key,
            type: SettingType::String,
            label: $label,
            default: '<p>Этот текст можно редактировать во встроенном редакторе</p>',
            children: [
            new SettingDefinition(
                key: 'background-color',
                type: SettingType::Color,
                default: 'bg-default',
                allowed: ColorPalette::bgTokens(),
                label: 'Цвет фона',
            ),
            self::getPaddingsDefinition(),
            self::getMarginDefinition(),
            self::getBordersDefinition(),
        ],
        );
    }

    public static function getTypeTextDefinition($key = 'text', $label = 'Текст')
    {
        return new SettingDefinition(
            key: $key,
            type: SettingType::Array,
            label: $label,
            default: [],
            children: [
            new SettingDefinition(
                key: 'title',
                type: SettingType::String,
                public: false,
                default: 'Одно кольцо, чтобы править всеми',
                label: 'Заголовок',
                children: [
                self::getTextSettingsDefinition(),
            ],
            ),
            new SettingDefinition(
                key: 'summary',
                type: SettingType::String,
                public: false,
                default: 'Хотелось бы мне, чтобы это случилось в другое время — не в моё. — И мне бы тоже, да и всем, кто дожил до таких времён. Но выбирать не дано. Мы можем только решить, как распорядиться своим временем',
                label: 'Сводка',
                children: [
                self::getTextSettingsDefinition(),
            ],
            ),
            new SettingDefinition(
                key: 'text',
                type: SettingType::String,
                public: false,
                default: 'А быть может, каждый из вас уже начал — не заметив этого — тот единственный путь, который предназначен ему судьбой. В странные времена довелось мне жить! Мы веками разводили скот, пахали землю, строили дома, мастерили орудия, помогали гондорцам в битвах за Минас Тирит. Все это мы называли обычной человеческой жизнью, и нам казалось, что таким путем идет весь мир. Нас мало беспокоило, что происходит за пределами нашей страны. Об этом пелось в песнях, но мы забывали эти песни или пели их только детям, просто так, бездумно, по привычке. И вот эти песни напомнили о себе, отыскали нас в самом неожиданном месте и обрели видимое обличье!',
                label: 'Текст',
                children: [
                self::getTextSettingsDefinition(),
            ],
            ),
            new SettingDefinition(
                key: 'cols',
                type: SettingType::Int,
                default: 12,
                label: 'Ширина блока, колонок',
            ),
            new SettingDefinition(
                key: 'text-block-position',
                type: SettingType::Select,
                default: 'center',
                allowed: [
                'flex-start',
                'center',
                'flex-end',
            ],
                label: 'Позиция блока текста',
            ),
            new SettingDefinition(
                key: 'background-color',
                type: SettingType::Color,
                default: 'bg-default',
                allowed: ColorPalette::bgTokens(),
                label: 'Цвет фона',
            ),
            self::getPaddingsDefinition(),
            self::getMarginDefinition(),
            self::getBordersDefinition(),
        ],
        );
    }

    public static function getTypeTestDefinition()
    {
        return new SettingDefinition(
            key: 'test',
            type: SettingType::Array,
            label: 'Задание',
            default: [],
            children: [
            new SettingDefinition(
                key: 'ethalon',
                type: SettingType::String,
                public: false,
                default: 'Одно кольцо, чтобы править всеми',
                label: 'Правильный ответ'
            ),
            new SettingDefinition(
                key: 'answer',
                type: SettingType::String,
                public: false,
                label: 'Ответ',
                children: [
            ],
            ),

        ],
        );
    }

    public static function getContentTypes($imagesPurpose = FilePurpose::Gallery1)
    {
        return [
            self::getTypeTextDefinition('text3', 'Текст'),
            self::getTypeHtmltextDefinition('htmltext', 'Текст'),
            self::getTypeGalleryDefinition('gallery', 'Изображения', $imagesPurpose),
        ];
    }

    public static function getBoolValueSettingDefinition()
    {
        return new SettingDefinition(
            key: 'value',
            type: SettingType::Bool,
            default: false,
            label: 'Значение'
        );
    }


    /** @return array<string, SettingDefinition> */
    public static function map(): array
    {
        return [
            'text_image' => new SettingDefinition(
                key: 'type',
                type: SettingType::Layout,
                default: 'single',
                allowed: ['single', 'row', 'col'],
                label: 'Текст + Изображение',
                description: 'Блок с текстом и галереей',
                children: [
                new SettingDefinition(
                    key: 'icon',
                    type: SettingType::Icon,
                    default: 'i-lucide-layout-panel-left',
                    label: 'Иконка',
                ),
                new SettingDefinition(
                    key: 'first-block-cols',
                    type: SettingType::Int,
                    default: 12,
                    label: 'Ширина первого блока, колонок',
                ),
                new SettingDefinition(
                    key: 'first-block-position',
                    type: SettingType::Select,
                    default: 'center',
                    allowed: [
                    'flex-start',
                    'center',
                    'flex-end',
                ],
                    label: 'Позиция первого блока',
                ),
                new SettingDefinition(
                    key: 'background-color',
                    type: SettingType::Color,
                    default: 'bg-default',
                    allowed: ColorPalette::bgTokens(),
                    label: 'Цвет фона',
                ),
                new SettingDefinition(
                    key: 'background-image',
                    public: false,
                    type: SettingType::Image,
                    default: [],
                    params: ['purpose' => FilePurpose::BackgroundImage, 'count' => 1, 'display-images' => true],
                    label: 'Фоновое изображение',
                ),
                new SettingDefinition(
                    key: 'background-size',
                    type: SettingType::Select,
                    default: 'auto',
                    allowed: ['auto', 'cover', 'contain', '50%', '100%'],
                    label: 'Размер фона',
                ),
                new SettingDefinition(
                    key: 'background-position',
                    type: SettingType::Select,
                    default: 'top',
                    allowed: ['top', 'left', 'center', 'top left', 'top right', 'bottom left', 'bottom right', 'center center'],
                    label: 'Положение фона',
                ),
                self::getPaddingsDefinition(),
                self::getMarginDefinition(),
                self::getBordersDefinition(),
                new SettingDefinition(
                    key: 'content-blocks',
                    type: SettingType::Array,
                    default: [],
                    label: 'Блоки',
                    children: [
                        new SettingDefinition(
                            key: 'block1',
                            type: SettingType::ContentType,
                            default: 'text',
                            allowed: ['text', 'gallery', 'htmltext'],
                            label: 'Блок1',
                            children: self::getContentTypes(FilePurpose::Gallery1)
                        ),
                        new SettingDefinition(
                            key: 'block2',
                            type: SettingType::ContentType,
                            default: 'gallery',
                            allowed: ['text', 'gallery', 'htmltext'],
                            label: 'Блок2',
                            children: self::getContentTypes(FilePurpose::Gallery2)
                        ),
                        new SettingDefinition(
                            key: 'test',
                            type: SettingType::Array,
                            label: 'Задание',
                            default: [],
                            children: [
                            new SettingDefinition(
                                key: 'ethalon',
                                type: SettingType::String,
                                public: false,
                                default: 'Одно кольцо, чтобы править всеми',
                                label: 'Правильный ответ'
                            ),
                            new SettingDefinition(
                                key: 'block-cols',
                                type: SettingType::Int,
                                default: 24,
                                label: 'Ширина блока, колонок',
                            ),
                            new SettingDefinition(
                                key: 'block-position',
                                type: SettingType::Select,
                                default: 'center',
                                label: 'Позиция блока',
                                allowed: [
                                'flex-start',
                                'center',
                                'flex-end',
                            ],
                            ),
                            new SettingDefinition(
                                key: 'answer',
                                type: SettingType::String,
                                public: false,
                                label: 'Ответ',
                                children: [
                                new SettingDefinition(
                                    key: 'text-color',
                                    type: SettingType::Color,
                                    default: 'accent-solution',
                                    allowed: ColorPalette::accentTokens(),
                                    label: 'Цвет текста',
                                ),
                                new SettingDefinition(
                                    key: 'background-color',
                                    type: SettingType::Color,
                                    default: 'bg-default',
                                    allowed: ColorPalette::bgTokens(),
                                    label: 'Цвет фона',
                                ),
                                new SettingDefinition(
                                    key: 'answerHeight',
                                    type: SettingType::Int,
                                    default: 250,
                                    public: false,
                                    label: 'Высота ответа',
                                ),
                                new SettingDefinition(
                                    key: 'answer-tilte',
                                    type: SettingType::String,
                                    public: false,
                                    default: 'Ответ',
                                    label: 'Заголовок ответа ученика',
                                    children: [self::getTextSettingsDefinition(),]
                                ),
                                self::getPaddingsDefinition(),
                                self::getMarginDefinition(),
                                self::getBordersDefinition(),
                            ],
                            ),

                        ],
                        )
                    ]
                ),
            ],
            ),
            'completer' => new SettingDefinition(
                key: 'type',
                type: SettingType::Layout,
                default: 'completer',
                /*allowed: ['completer'],*/
                label: 'Разделитель',
                description: 'Блок в котором ученик должен ставить отметки о прохождении материала',
                children: [
                    new SettingDefinition(
                        key: 'first-block-cols',
                        type: SettingType::Int,
                        default: 24,
                        label: 'Ширина блока, колонок',
                    ),
                    new SettingDefinition(
                        key: 'first-block-position',
                        type: SettingType::Select,
                        default: 'center',
                        label: 'Позиция блока',
                        allowed: [
                        'flex-start',
                        'center',
                        'flex-end',
                    ],

                    ),
                    new SettingDefinition(
                        key: 'background-color',
                        type: SettingType::Color,
                        default: 'bg-default',
                        allowed: ColorPalette::bgTokens(),
                        label: 'Цвет фона',
                    ),
                    new SettingDefinition(
                        key: 'text-color',
                        type: SettingType::Color,
                        default: 'accent-solution',
                        allowed: ColorPalette::accentTokens(),
                        label: 'Цвет подписей',
                    ),
                    new SettingDefinition(
                        key: 'steps-color',
                        type: SettingType::Color,
                        default: 'accent-solution',
                        allowed: ColorPalette::accentTokens(),
                        label: 'Цвет шагов',
                    ),
                    self::getPaddingsDefinition(),
                    self::getMarginDefinition(),
                    self::getBordersDefinition(),
                    new SettingDefinition(
                        key: 'steps',
                        type: SettingType::MultiSelect,
                        default: ['step1', 'step2', 'step3'],
                        allowed: ['step1', 'step2', 'step3'],
                        children: [
                        new SettingDefinition(
                            key: 'step1',
                            type: SettingType::String,
                            default: 'Прочитал',
                            label: 'Шаг 1',
                            children: [
                                new SettingDefinition(
                                    key: 'icon',
                                    type: SettingType::Icon,
                                    default: 'i-lucide-book-open',
                                    label: 'Иконка',
                                ),
                                self::getBoolValueSettingDefinition()
                            ]
                        ),
                        new SettingDefinition(
                            key: 'step2',
                            type: SettingType::String,
                            default: 'Разобрал',
                            label: 'Шаг 2',
                            children: [
                                new SettingDefinition(
                                    key: 'icon',
                                    type: SettingType::Icon,
                                    default: 'i-lucide-puzzle',
                                    label: 'Иконка',
                                ),
                                self::getBoolValueSettingDefinition()
                            ]
                        ),
                        new SettingDefinition(
                            key: 'step3',
                            type: SettingType::String,
                            default: 'Выучил',
                            label: 'Шаг 3',
                            children: [
                                new SettingDefinition(
                                    key: 'icon',
                                    type: SettingType::Icon,
                                    default: 'i-lucide-graduation-cap',
                                    label: 'Иконка',
                                ),
                                self::getBoolValueSettingDefinition()
                            ]
                        ),
                    ],
                    ),

                ]
            ),
        ];
    }

    /**
     * Возвращает метаданные блока для палитры конструктора.
     */
    public static function getMeta(string $code): ?array
    {
        $def = self::for($code);
        if (!$def) return null;

        // Ищем иконку в дереве настроек
        $icon = 'i-lucide-square';
        foreach ($def->children ?? [] as $child) {
            if ($child->key === 'icon') {
                $icon = $child->default ?? $icon;
                break;
            }
        }

        return [
            'code' => $code,
            'name' => $def->label ?? $code,
            'description' => $def->description ?? '',
            'icon' => $icon,
        ];
    }

    public static function for(string $code): ?SettingDefinition
    {
        return self::map()[$code] ?? null;
    }

    public static function codes(): array
    {
        return array_keys(self::map());
    }

    public static function has(string $code): bool
    {
        return array_key_exists($code, self::map());
    }

    // ==================== Сериализация для API ====================

    public static function serializeSchema(string $code): array
    {
        $def = self::for($code);
        return $def ? self::serializeDefinition($def) : [];
    }

    public static function serializeDefinition(SettingDefinition $def): array
    {
        static $fields = null;

        if ($fields === null) {
            $fields = array_map(
                fn(\ReflectionParameter $p) => $p->getName(),
                (new \ReflectionClass(SettingDefinition::class))
                    ->getConstructor()
                    ->getParameters(),
            );
        }

        $node = [];

        foreach ($fields as $field) {
            if ($field === 'children') {
                continue;
            }

            $value = $def->{$field};

            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            }

            $node[$field] = $value;
        }

        if ($def->isContainer() || !empty($def->children)) {
            $node['children'] = array_map(
                fn(SettingDefinition $child) => self::serializeDefinition($child),
                $def->children ?? [],
            );
        }

        return $node;
    }
}
