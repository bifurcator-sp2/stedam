<?php

namespace Database\Seeders;

use App\Models\Block;
use App\Models\LessonBlock;
use App\Models\Taxonomy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SystemTestsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Загружаем все опубликованные шаблоны заданий
        $templates = Block::query()
            ->where('is_test', true)
            ->where('published', true)
            ->get();

        if ($templates->isEmpty()) {
            $this->command->error('Нет опубликованных шаблонов с is_test = true.');
            return;
        }

        // 2. Кэшируем настройки
        $cachedSettings = [];
        foreach ($templates as $blockProto) {
            $rawSettings = $blockProto->settings ?? [];

            // Пропускаем, если в БД действительно пусто
            if (empty($rawSettings)) {
                $this->command->warn("Внимание: У шаблона #{$blockProto->id} пустые настройки в БД.");
                $cachedSettings[$blockProto->id] = [];
                continue;
            }

            // Вырезаем приватные поля
            $filtered = $this->filterPublic($rawSettings);

            // Если фильтрация вернула пустоту, используем оригинальные настройки (чтобы не было пустых заданий)
            if (empty($filtered)) {
                $filtered = $rawSettings;
            }

            $cachedSettings[$blockProto->id] = $filtered;
        }

        // 3. Находим все листья таксономии
        $leafTaxonomies = Taxonomy::doesntHave('children')->pluck('id')->toArray();

        if (empty($leafTaxonomies)) {
            $this->command->error('В таксономии нет листьев (узлов без дочерних элементов).');
            return;
        }

        $total = 10000;
        $chunkSize = 500;

        $this->command->info("Начинаем создание {$total} системных заданий...");

        // 4. Генерируем задания пачками
        for ($i = 0; $i < $total; $i += $chunkSize) {
            $currentChunkSize = min($chunkSize, $total - $i);

            DB::transaction(function () use ($templates, $cachedSettings, $leafTaxonomies, $currentChunkSize) {
                for ($j = 0; $j < $currentChunkSize; $j++) {
                    $template = $templates->random();
                    $taxonomyId = $leafTaxonomies[array_rand($leafTaxonomies)];

                    $test = LessonBlock::create([
                        'user_id'   => null,
                        'lesson_id' => null,
                        'block_id'  => $template->id,
                        'settings'  => $cachedSettings[$template->id],
                        'order'     => 0,
                    ]);

                    $test->taxonomies()->attach($taxonomyId);
                }
            });

            $this->command->info("Создано: " . ($i + $currentChunkSize) . " из {$total}");
        }

        $this->command->info('Готово! Все системные задания успешно созданы.');
    }

    /**
     * Рекурсивно оставляет только публичные узлы.
     * Работает как с одиночным объектом, так и с массивом.
     */
    private function filterPublic(array $node): array
    {
        $isPublic = $node['public'] ?? false;
        $filteredChildren = [];

        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $child) {
                if (!is_array($child)) continue;

                $filteredChild = $this->filterPublic($child);
                // Если ребенок публичный или у него есть публичные внуки
                if (!empty($filteredChild)) {
                    $filteredChildren[] = $filteredChild;
                }
            }
        }

        // Если узел непубличный и у него нет публичных детей — удаляем его
        if (!$isPublic && empty($filteredChildren)) {
            return [];
        }

        // Если это узел, возвращаем его с отфильтрованными детьми
        $result = $node;
        if (isset($node['children'])) {
            $result['children'] = $filteredChildren;
        }

        return $result;
    }
}
