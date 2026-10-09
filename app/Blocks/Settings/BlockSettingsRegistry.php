<?php

namespace App\Blocks\Settings;
use App\Enums\FilePurpose;
class BlockSettingsRegistry
{


    public static function getBordersDefinition($key = 'border', $label ='Границы'): SettingDefinition
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
                default: 1,
                label: 'Слева',
                                ),
            new SettingDefinition(
                key: 'right',
                type: SettingType::Int,
                default: 1,
                label: 'Справа',
                                ),
            new SettingDefinition(
                key: 'top',
                type: SettingType::Int,
                default: 1,
                label: 'Сверху',
                                ),
            new SettingDefinition(
                key: 'bottom',
                type: SettingType::Int,
                default: 1,
                label: 'Снизу',
                                ),
        ],
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
                default: 1,
                label: 'Слева',
                                ),
            new SettingDefinition(
                key: 'right',
                type: SettingType::Int,
                default: 1,
                label: 'Справа',
                                ),
            new SettingDefinition(
                key: 'top',
                type: SettingType::Int,
                default: 1,
                label: 'Сверху',
                                ),
            new SettingDefinition(
                key: 'bottom',
                type: SettingType::Int,
                default: 1,
                label: 'Снизу',
                                ),
        ],
                        );
    }

    /** @return array<string, SettingDefinition[]> */
    public static function map(): array
    {
        return [
            'text_image' => [
                new SettingDefinition(
                    key: 'icon',
                    type: SettingType::Icon,
                    default: 'i-lucide-layout-panel-left',
                    label: 'Иконка',
                ),
                new SettingDefinition(
                    key: 'layout',
                    type: SettingType::Select,
                    default: 'text-right',
                    allowed: ['text', 'image', 'text-right', 'text-left', 'text-top', 'text-bottom'],
                    label: 'Раскладка',
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
                    type: SettingType::Image,
                    default: [],
                    allowed: [FilePurpose::BackgroundImage, 1],
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
                // Array изображений: children — плоский список полей элемента
                new SettingDefinition(
                    key: 'images',
                    type: SettingType::Array,
                    label: 'Изображения',
                    default: [],
                    children: [
                    new SettingDefinition(
                        key: 'count',
                        type: SettingType::Int,
                        default: 1,
                        label: 'Количество изображений',
                        ),
                    new SettingDefinition(
                        key: 'columns',
                        type: SettingType::Int,
                        default: 1,
                        label: 'Количество колонок',
                        ),
                    new SettingDefinition(
                        key: 'distance',
                        type: SettingType::Int,
                        default: 1,
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
                    self::getBordersDefinition('image-border', 'Рамки изображений'),
                    self::getPaddingsDefinition(),
                    self::getMarginDefinition(),
                    self::getBordersDefinition(),
                ],
                ),

                // Array текстов: children — плоский список полей элемента
                new SettingDefinition(
                    key: 'text',
                    type: SettingType::Array,
                    label: 'Текст',
                    default: [],
                    children: [
                    new SettingDefinition(
                        key: 'title',
                        type: SettingType::String,
                        default: 'Одно кольцо, чтобы править всеми',
                        label: 'Превью заголовка',
                        ),
                    new SettingDefinition(
                        key: 'text',
                        type: SettingType::String,
                        default: 'А быть может, каждый из вас уже начал — не заметив этого — тот единственный путь, который предназначен ему судьбой. В странные времена довелось мне жить! Мы веками разводили скот, пахали землю, строили дома, мастерили орудия, помогали гондорцам в битвах за Минас Тирит. Все это мы называли обычной человеческой жизнью, и нам казалось, что таким путем идет весь мир. Нас мало беспокоило, что происходит за пределами нашей страны. Об этом пелось в песнях, но мы забывали эти песни или пели их только детям, просто так, бездумно, по привычке. И вот эти песни напомнили о себе, отыскали нас в самом неожиданном месте и обрели видимое обличье!',
                        label: 'Превью текста',
                        ),
                    new SettingDefinition(
                        key: 'editor',
                        type: SettingType::Bool,
                        default: 0,
                        label: 'Разрешить редактор',
                        ),
                    new SettingDefinition(
                        key: 'cols',
                        type: SettingType::Int,
                        default: 6,
                        label: 'Ширина блока, колонок',
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
                        key: 'header-type',
                        type: SettingType::Select,
                        default: 'h2',
                        allowed: ['h2', 'h3', 'h4', 'h5'],
                        label: 'Тип заголовка',
                        ),
                    new SettingDefinition(
                        key: 'text-cols',
                        type: SettingType::Int,
                        default: 1,
                        label: 'Количество колонок текста',
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
                ),
            ],

            'table' => [
                new SettingDefinition(
                    key: 'icon',
                    type: SettingType::String,
                    default: 'i-lucide-table',
                ),
                new SettingDefinition(
                    key: 'rows',
                    type: SettingType::Int,
                    default: 3,
                    label: 'Строк',
                ),
                new SettingDefinition(
                    key: 'cols',
                    type: SettingType::Int,
                    default: 3,
                    label: 'Столбцов',
                ),
                new SettingDefinition(
                    key: 'border',
                    type: SettingType::Bool,
                    default: true,
                    label: 'Границы',
                ),
            ],

            'diagram' => [
                new SettingDefinition(
                    key: 'icon',
                    type: SettingType::String,
                    default: 'i-lucide-chart-column',
                ),
                new SettingDefinition(
                    key: 'type',
                    type: SettingType::Select,
                    default: 'bar',
                    allowed: ['bar', 'line', 'pie', 'doughnut'],
                    label: 'Тип диаграммы',
                ),
                new SettingDefinition(
                    key: 'height',
                    type: SettingType::Int,
                    default: 300,
                    label: 'Высота',
                ),
                new SettingDefinition(
                    key: 'show_legend',
                    type: SettingType::Bool,
                    default: true,
                    label: 'Легенда',
                ),
            ],

            'cover' => [
                new SettingDefinition(
                    key: 'icon',
                    type: SettingType::String,
                    default: 'i-lucide-image',
                ),
                new SettingDefinition(
                    key: 'bg_src',
                    type: SettingType::String,
                    default: '',
                    label: 'Фоновое изображение',
                ),
                new SettingDefinition(
                    key: 'title',
                    type: SettingType::String,
                    default: 'Заголовок',
                    label: 'Заголовок',
                ),
                new SettingDefinition(
                    key: 'subtitle',
                    type: SettingType::String,
                    default: '',
                    label: 'Подзаголовок',
                ),
                new SettingDefinition(
                    key: 'align',
                    type: SettingType::Select,
                    default: 'center',
                    allowed: ['left', 'center', 'right'],
                    label: 'Выравнивание',
                ),
                new SettingDefinition(
                    key: 'height_vh',
                    type: SettingType::Int,
                    default: 100,
                    label: 'Высота (vh)',
                ),
            ],

            'index' => [
                new SettingDefinition(
                    key: 'icon',
                    type: SettingType::String,
                    default: 'i-lucide-list',
                ),
                new SettingDefinition(
                    key: 'style',
                    type: SettingType::Select,
                    default: 'list',
                    allowed: ['list', 'numbered', 'compact'],
                    label: 'Стиль',
                ),
                new SettingDefinition(
                    key: 'max_depth',
                    type: SettingType::Int,
                    default: 3,
                    label: 'Макс. глубина',
                ),
                new SettingDefinition(
                    key: 'sticky',
                    type: SettingType::Bool,
                    default: false,
                    label: 'Прилипание',
                ),
            ],

            'divider' => [
                new SettingDefinition(
                    key: 'icon',
                    type: SettingType::String,
                    default: 'i-lucide-minus',
                ),
                new SettingDefinition(
                    key: 'style',
                    type: SettingType::Select,
                    default: 'solid',
                    allowed: ['solid', 'dashed', 'dotted', 'double'],
                    label: 'Стиль',
                ),
                new SettingDefinition(
                    key: 'color',
                    type: SettingType::Select,
                    default: 'default',
                    allowed: ['default', 'primary', 'muted', 'danger'],
                    label: 'Цвет',
                ),
                new SettingDefinition(
                    key: 'thickness',
                    type: SettingType::Int,
                    default: 1,
                    label: 'Толщина (px)',
                ),
                new SettingDefinition(
                    key: 'margin_y',
                    type: SettingType::Int,
                    default: 16,
                    label: 'Отступ сверху/снизу',
                ),
            ],
        ];
    }

    /** @return SettingDefinition[] */
    public static function for(string $code): array
    {
        return self::map()[$code] ?? [];
    }

    public static function defaults(string $code): array
    {
        $defaults = [];
        foreach (self::for($code) as $def) {
            $defaults[$def->key] = $def->defaultValue();
        }
        return $defaults;
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
        return array_map(
            fn(SettingDefinition $def) => self::serializeDefinition($def),
            self::for($code),
        );
    }

    public static function serializeDefinition(SettingDefinition $def): array
    {
        $node = [
            'key' => $def->key,
            'type' => $def->type->value,
            'label' => $def->label,
            'description' => $def->description,
            'required' => $def->required,
            'allowed' => $def->allowed,
            'default' => $def->default,
        ];

        // Для контейнеров — children как есть (плоский список)
        if ($def->isContainer()) {
            $node['children'] = array_map(
                fn(SettingDefinition $child) => self::serializeDefinition($child),
                $def->children ?? [],
            );
        }

        return $node;
    }
}
