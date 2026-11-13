<?php

namespace Database\Seeders;

use App\Models\LessonType;
use Illuminate\Database\Seeder;

/**
 * Справочник типов уроков.
 *
 * next_lesson_threshold — доля предыдущего урока, необходимая для открытия
 * следующего. Значения редактируемы через справочник; здесь — дефолты.
 */
class LessonTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'code' => 'class_work',
                'next_lesson_threshold' => 0.75,
                'color' => '#4CAF50',
                'sort' => 10,
                'ru' => 'Классная работа',
                'en' => 'Class Work',
                'description_ru' => 'Работа выполняется на уроке',
                'description_en' => 'Work performed during the lesson',
            ],
            [
                'code' => 'home_work',
                'next_lesson_threshold' => 0.333,
                'color' => '#2196F3',
                'sort' => 20,
                'ru' => 'Домашняя работа',
                'en' => 'Home Work',
                'description_ru' => 'Работа выполняется дома',
                'description_en' => 'Work performed at home',
            ],
            [
                'code' => 'ind_work',
                'next_lesson_threshold' => 0.50,
                'color' => '#FF9800',
                'sort' => 30,
                'ru' => 'Самостоятельная работа',
                'en' => 'Independent Work',
                'description_ru' => 'Самостоятельная проверка знаний',
                'description_en' => 'Independent knowledge check',
            ],
            [
                'code' => 'control_work',
                'next_lesson_threshold' => 0.666,
                'color' => '#F44336',
                'sort' => 40,
                'ru' => 'Контрольная работа',
                'en' => 'Control Work',
                'description_ru' => 'Контрольная работа по теме',
                'description_en' => 'Control work on the topic',
            ],
        ];

        foreach ($types as $data) {
            $type = LessonType::updateOrCreate(
                ['code' => $data['code']],
                [
                    'next_lesson_threshold' => $data['next_lesson_threshold'],
                    'color' => $data['color'],
                    'sort' => $data['sort'],
                ]
            );

            $type->translations()->updateOrCreate(
                ['locale' => 'ru'],
                ['title' => $data['ru'], 'description' => $data['description_ru']]
            );

            $type->translations()->updateOrCreate(
                ['locale' => 'en'],
                ['title' => $data['en'], 'description' => $data['description_en']]
            );
        }
    }
}
