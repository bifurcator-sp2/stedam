<?php

namespace Database\Seeders;

use App\Models\BlockType;
use App\Models\User;
use Illuminate\Database\Seeder;

class BlockTypeSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::first();

        if (! $owner) {
            $this->command->warn('Нет пользователей — user_id будет null. Создайте админа и запустите сидер заново.');
        }

        $types = [
            [
                'code' => 'text_image',
                'ru' => [
                    'name' => 'Текст + Изображение',
                    'description' => 'Блок с текстом и изображениями.',
                ],
                'en' => [
                    'name' => 'Text + Image',
                    'description' => 'Text and images.',
                ],
            ],
            [
                'code' => 'table',
                'ru' => [
                    'name' => 'Таблица',
                    'description' => 'Табличное представление данных с настраиваемым количеством строк и столбцов.',
                ],
                'en' => [
                    'name' => 'Table',
                    'description' => 'Tabular data representation with configurable rows and columns.',
                ],
            ],
            [
                'code' => 'diagram',
                'ru' => [
                    'name' => 'Диаграмма',
                    'description' => 'Визуализация данных в виде диаграммы.',
                ],
                'en' => [
                    'name' => 'Diagram',
                    'description' => 'Data visualization as a chart.',
                ],
            ],
            [
                'code' => 'cover',
                'ru' => [
                    'name' => 'Обложка',
                    'description' => 'Полноэкранная обложка с фоновым изображением, заголовком и подзаголовком.',
                ],
                'en' => [
                    'name' => 'Cover',
                    'description' => 'Full-screen cover with background image, title, and subtitle.',
                ],
            ],
            [
                'code' => 'table_of_contents',
                'ru' => [
                    'name' => 'Оглавление',
                    'description' => 'Автоматически собранное оглавление со ссылками на разделы документа.',
                ],
                'en' => [
                    'name' => 'Table of Contents',
                    'description' => 'Automatically generated table of contents with links to document sections.',
                ],
            ],
            [
                'code' => 'divider',
                'ru' => [
                    'name' => 'Разделители',
                    'description' => 'Горизонтальные разделители для визуального разделения секций контента.',
                ],
                'en' => [
                    'name' => 'Dividers',
                    'description' => 'Horizontal dividers for visually separating content sections.',
                ],
            ],
        ];

        foreach ($types as $data) {
            $blockType = BlockType::updateOrCreate(
                ['code' => $data['code']],
                [
                    'type' => 'info',
                    'default_settings' => [],
                    'user_id' => $owner?->id,
                ]
            );

            $blockType->translations()->updateOrCreate(
                ['locale' => 'ru'],
                [
                    'name' => $data['ru']['name'],
                    'description' => $data['ru']['description'],
                ]
            );

            $blockType->translations()->updateOrCreate(
                ['locale' => 'en'],
                [
                    'name' => $data['en']['name'],
                    'description' => $data['en']['description'],
                ]
            );
        }

        $this->command->info('Block types seeded: ' . count($types));
    }
}
