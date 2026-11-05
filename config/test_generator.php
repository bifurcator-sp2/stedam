<?php

declare(strict_types=1);

return [

    'default_subject'    => 'math',
    'default_difficulty' => 0.5,

    /**
     * Витрина для /api/catalog и группировка топиков по предметам.
     *
     * Mapping "узел дерева → генератор" больше не хранится здесь:
     * он выводится из структуры папок app/Blocks/TestGenerator/Generators.
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
    ],
];
