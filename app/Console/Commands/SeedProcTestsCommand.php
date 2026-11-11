<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Blocks\Settings\BlockSettingsRegistry;
use App\Domain\Blocks\Settings\SettingsNormalizer;
use App\Models\Block;
use App\Models\LessonBlock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Приводит БД к состоянию, при котором существуют ровно N процедурных
 * заданий (is_proc = true) с линейно распределённой сложностью.
 *
 * Зачем: тестовое мероприятие и развёртывание на машинах новых разработчиков.
 * После запуска команды генератор всегда находит задания на любой вкус —
 * от самых простых до самых сложных, без ручной настройки.
 *
 * Что делает:
 *   1. Находит (или создаёт) шаблон Block с is_proc = true.
 *   2. Загружает его settings — программно, через BlockSettingsRegistry,
 *      без захардкоженного JSON.
 *   3. Создаёт/обновляет N LessonBlock (lesson_id = null, is_proc = true)
 *      с complexity, равномерно распределённой на [min, max].
 *   4. Лишние proc-задания (если было больше N) мягко удаляет.
 *
 * Идемпотентность:
 *   Повторный запуск обновляет те же строки — id существующих заданий
 *   не меняются. Это важно: на них уже могут ссылаться уроки через
 *   lesson_lesson_block_as_test.
 */
final class SeedProcTestsCommand extends Command
{
    protected $signature = 'tests:seed-proc
        {--count=20 : Сколько процедурных заданий должно быть в БД}
        {--min=0 : Минимальная сложность включительно}
        {--max=1 : Максимальная сложность включительно}
        {--template=text_image : code шаблона Block для proc-задания}
        {--submit : Сохранять шаблон с published = true}
        {--force : Не спрашивать подтверждения}';

    protected $description = 'Создаёт фиксированный набор процедурных заданий (is_proc) с линейной сложностью';

    /** Ключ узла в settings, который делает блок «процедурным». */
    private const PROC_NODE_KEY = 'testLabel';

    public function handle(): int
    {
        $count     = (int) $this->option('count');
        $min       = (float) $this->option('min');
        $max       = (float) $this->option('max');
        $template  = (string) $this->option('template');

        if ($count < 1) {
            $this->error('--count must be >= 1.');
            return self::FAILURE;
        }

        if ($min < 0.0 || $max > 1.0 || $max < $min) {
            $this->error('Ожидается 0.0 <= min <= max <= 1.0.');
            return self::FAILURE;
        }

        if (! BlockSettingsRegistry::has($template)) {
            $this->error(sprintf(
                'Неизвестный code шаблона: "%s". Доступны: %s.',
                $template,
                implode(', ', BlockSettingsRegistry::codes()),
            ));
            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            sprintf(
                'Все существующие is_proc задания будут удалены (soft delete), затем создано ровно %d шт. Continue?',
                $count,
            ),
        )) {
            return self::SUCCESS;
        }

        // --- 0. Жёстко прибиваем все is_proc задания ---
        $purged = $this->purgeAllProcTasks();
        $this->line(sprintf('Удалено (soft) is_proc заданий: %d.', $purged));

        $blockTemplate = $this->resolveTemplate($template);

        // settings задания — точная копия настроек блока-шаблона (block_id).
        $settings = (array) ($blockTemplate->settings ?? []);

        $complexities = $this->linearSeries($count, $min, $max);

        $created = 0;

        DB::transaction(function () use (
            $count,
            $complexities,
            $blockTemplate,
            $settings,
            &$created,
        ) {
            for ($i = 0; $i < $count; $i++) {
                LessonBlock::create([
                    'user_id'    => null,
                    'lesson_id'  => null,
                    'block_id'   => $blockTemplate->id,
                    'order'      => 0,
                    'complexity' => $complexities[$i],
                    'is_proc'    => true,
                    'settings'   => SettingsNormalizer::extractPublicDefaults($settings, $blockTemplate->settings),
                ]);

                $created++;
            }
        });

        $this->newLine();
        $this->info(sprintf('Готово: удалено %d, создано %d.', $purged, $created));
        $this->table(
            ['#', 'complexity'],
            array_map(
                static fn (int $i): array => [$i + 1, number_format($complexities[$i], 2)],
                array_keys($complexities),
            ),
        );

        if ($this->option('submit')) {
            $blockTemplate->update(['published' => true]);
            $this->line("Шаблон Block #{$blockTemplate->id} опубликован.");
        }

        $this->line('');
        $this->line('Дальше можно запустить: php artisan lessons:seed-lotr --force');
        $this->line('(эта команда переиспользует все глобальные proc-задания при генерации уроков)');

        return self::SUCCESS;
    }

    /**
     * Жёстко убирает все существующие процедурные задания (forceDelete).
     *
     * Команда разработческая, не для продакшена: строки стираются физически,
     * чтобы БД приходила к чистому состоянию «ровно 20 proc-заданий».
     * Привязки в lesson_lesson_block_as_test к удалённым заданиям
     * обрываются каскадом по FK.
     */
    private function purgeAllProcTasks(): int
    {
        $count = 0;

        // withTrashed + forceDelete: зачищаем и «живые», и ранее soft-deleted.
        LessonBlock::withTrashed()
            ->where('is_proc', true)
            ->chunkById(200, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    $row->forceDelete();
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Шаблон Block с заданным code (системный, user_id = null).
     * Если proc-шаблона ещё нет — создаём из схемы Registry;
     * settings берутся программно.
     */
    private function resolveTemplate(string $code): Block
    {
        /** @var Block|null $block */
        $block = Block::query()
            ->where('code', $code)
            ->where('is_proc', true)
            ->orderBy('id', 'desc')
            ->first();

        if ($block !== null) {
            return $block;
        }

        $block = Block::create([
            'code'      => $code,
            'user_id'   => null,
            'settings'  => $this->buildSettings(),
            'published' => true,
            'is_test'   => true,
            'is_proc'   => true,
        ]);

        $block->translations()->create([
            'locale'      => 'ru',
            'title'       => 'Процедурное задание',
            'description' => 'Шаблон для генерации процедурных задач',
        ]);

        return $block;
    }

    /**
     * Собирает settings процедурного задания из схемы Registry.
     *
     * Никакого JSON-хардкода: берём serializeSchema(), рекурсивно
     * превращаем дерево узлов в «сокращённую» форму {key, type, default,
     * children}, отбрасывая узлы с public = false, у которых нет
     * публичных потомков. В content-blocks гарантированно остаётся
     * узел `random` с `testLabel` — это сигнал для Learn, по которому
     * блок считается процедурным и подлежит генерации.
     *
     * @return array<string, mixed>
     */
    private function buildSettings(): array
    {
        $schema = BlockSettingsRegistry::serializeSchema('text_image');

        $settings = $this->toShortForm($schema);

        if (! is_array($settings)) {
            return [];
        }

        // Форсируем наличие proc-узлов random + testLabel в content-blocks.
        $settings['children'] = $this->injectProcNode($settings['children'] ?? []);

        return $settings;
    }

    /**
     * Сокращённая форма узла: оставляем key / type / default / public /
     * children, отбрасываем служебные поля (label, allowed, params).
     *
     * `public` сохраняется намеренно: Block.settings используется и как
     * схема для SettingsNormalizer::extractPublicDefaults() — ему нужен
     * этот флаг, чтобы знать, какие узлы считать публичными.
     *
     * Фильтрация как в SettingsNormalizer::extractPublicDefaults:
     * узел без public = true, у которого не осталось публичных потомков,
     * отбрасывается целиком. Исключение — proc-узлы (`random`, `testLabel`)
     * и узлы-контейнеры (без `key`), которые нужны Learn/снапшоту.
     *
     * @param array<string, mixed>|null $schema
     * @return array<string, mixed>|null
     */
    private function toShortForm(?array $schema): ?array
    {
        if (! is_array($schema) || ! isset($schema['key'])) {
            return null;
        }

        $key = (string) $schema['key'];

        // Дети — рекурсивно, с фильтрацией по public.
        $children = [];
        foreach ((array) ($schema['children'] ?? []) as $child) {
            if (! is_array($child)) {
                continue;
            }
            $short = $this->toShortForm($child);
            if ($short !== null) {
                $children[] = $short;
            }
        }

        // Узел оставляем, если он публичный или это proc-узел/контейнер.
        $isPublic       = (bool) ($schema['public'] ?? false);
        $isProcNode     = in_array($key, [self::PROC_NODE_KEY, 'random'], true);
        $isContainerKey = in_array($key, ['content-blocks', 'block1', 'block2'], true);

        if (! $isPublic && ! $isProcNode && ! $isContainerKey && $children === []) {
            return null;
        }

        $node = [
            'key'  => $key,
            'type' => $schema['type'] ?? 'string',
        ];

        // public — обязателен: тот же массив выступает «схемой» для
        // SettingsNormalizer::extractPublicDefaults().
        $node['public'] = $isPublic;

        if (array_key_exists('default', $schema)) {
            $node['default'] = $schema['default'];
        }

        if ($children !== []) {
            $node['children'] = $children;
        }

        return $node;
    }

    /**
     * Гарантирует наличие узла `random` (с `testLabel` внутри) среди
     * content-blocks. Если узел `content-blocks` уже есть — добавляет
     * `random` в его children; иначе создаёт `content-blocks` целиком.
     *
     * testLabel — ключевой сигнал для Learn: по нему блок считается
     * процедурным и подлежит генерации.
     *
     * @param array<int, array<string, mixed>> $children
     * @return array<int, array<string, mixed>>
     */
    private function injectProcNode(array $children): array
    {
        $procNode = [
            'key'      => 'random',
            'type'     => 'layout',
            'public'   => true,
            'default'  => '',
            'children' => [
                [
                    'key'     => self::PROC_NODE_KEY,
                    'type'    => 'string',
                    'public'  => true,
                    'default' => 'Содержание задачи / Условие / Вопрос',
                ],
            ],
        ];

        foreach ($children as $index => $child) {
            if (($child['key'] ?? null) !== 'content-blocks') {
                continue;
            }

            // Уже есть random — ничего не делаем.
            foreach ($child['children'] ?? [] as $inner) {
                if (($inner['key'] ?? null) === 'random') {
                    return $children;
                }
            }

            // Добавляем random в существующий content-blocks.
            $children[$index]['children'][] = $procNode;

            return $children;
        }

        // content-blocks вообще нет — создаём с нуля.
        $children[] = [
            'key'      => 'content-blocks',
            'type'     => 'array',
            'public'   => true,
            'default'  => [],
            'children' => [$procNode],
        ];

        return $children;
    }

    /**
     * Линейный ряд сложностей: count центров равных интервалов на [min, max].
     *
     * Отрезок делится на $count бинов шириной ($max - $min) / $count,
     * возвращается середина каждого бина. Свойства:
     *   - при count = 20 на [0, 1]: 0.025, 0.075, … 0.975 (шаг 1/20);
     *   - ни min, ни max в ряд не попадают (концы — границы, не центры);
     *   - ряд строго возрастающий, без дублей.
     *
     * Для count = 1 возвращает центр отрезка.
     *
     * @return array<int, float>
     */
    private function linearSeries(int $count, float $min, float $max): array
    {
        $binWidth = ($max - $min) / $count;

        return array_map(
            static fn (int $i): float => round($min + $binWidth * ($i + 0.5), 4),
            range(0, $count - 1),
        );
    }
}
