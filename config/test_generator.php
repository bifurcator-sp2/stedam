<?php

declare(strict_types=1);

return [

    'default_subject'    => 'math',
    'default_difficulty' => 0.5,

    /**
     * Витрина для /api/catalog и группировка топиков по предметам.
     *
     * Mapping "узел дерева → генератор" здесь НЕ хранится:
     * он выводится из структуры папок app/Domain/Blocks/TestGenerator/Generators.
     *
     * Ключ subject = первый сегмент resolver-пути ('math', 'physics', ...).
     * Ключи внутри topics — короткие ярлыки; контроллеры сопоставляют их
     * по префиксу реальных resolver-путей (math.<topic>.*).
     *
     * Добавляя новую дисциплину, достаточно:
     *   1) создать папку Generators/<Subject>/... с генераторами;
     *   2) добавить сюда блок с 'title' и желаемыми topic-ярлыками.
     */
    'subjects' => [

        'math' => [
            'title'  => 'Математика',
            'topics' => [
                'arithmetic' => ['title' => 'Арифметика'],
                'algebra'    => ['title' => 'Алгебра'],
                'geometry'   => ['title' => 'Геометрия'],
            ],
        ],

        // Заготовки для будущих дисциплин. Пока topics пустые —
        // в /api/catalog они не отображаются, пока нет генераторов.
        'physics' => [
            'title'  => 'Физика',
            'topics' => [
                // 'mechanics'   => ['title' => 'Механика'],
                // 'electricity' => ['title' => 'Электричество'],
                // 'optics'      => ['title' => 'Оптика'],
            ],
        ],

        'chemistry' => [
            'title'  => 'Химия',
            'topics' => [
                // 'inorganic' => ['title' => 'Неорганическая химия'],
                // 'organic'   => ['title' => 'Органическая химия'],
            ],
        ],

    ],
];
