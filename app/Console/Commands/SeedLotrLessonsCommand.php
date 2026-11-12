<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\LessonBlocks\Actions\AddBlockAction;
use App\Application\LessonBlocks\DTOs\AddBlockData;
use App\Application\LessonBlocks\Ports\LessonBlockRepositoryInterface;
use App\Application\Lessons\Actions\CreateLessonAction;
use App\Application\Lessons\Actions\DeleteLessonAction;
use App\Application\Lessons\Actions\UpdateLessonAction;
use App\Application\Lessons\DTOs\CreateLessonData;
use App\Application\Lessons\DTOs\UpdateLessonData;
use App\Application\Lessons\Ports\LessonRepositoryInterface;
use App\Application\Tasks\Ports\TaskRepositoryInterface;
use App\Application\TasksProc\Ports\ProcTaskRepositoryInterface;
use App\Console\Commands\Concerns\LotrTextCorpus;
use App\Domain\LessonBlocks\ValueObjects\LessonBlockId;
use App\Domain\Lessons\ValueObjects\LessonId;
use App\Domain\Lessons\ValueObjects\UserId;
use App\Models\LessonBlock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Удаляет уроки (по выбору) и сеет LOTR-тематические уроки.
 *
 * РЕАЛИЗАЦИЯ НА ДОМЕНЕ (DDD):
 *   — удаление урока       → DeleteLessonAction (по id, idempotent);
 *   — создание урока       → CreateLessonAction (черновик);
 *   — шапка/таксономия     → UpdateLessonAction;
 *   — info-блоки           → AddBlockAction (own-блок со своими settings);
 *   — привязка заданий     → LessonBlockRepository::attachToLesson
 *                            (+ save урока → пересчёт complexity хуком);
 *   — публикация           → UpdateLessonAction(published=true);
 *   — пулы заданий         → TaskRepository / ProcTaskRepository (read-side);
 *   — листья таксономий    → LessonBlockRepository::pluckLeafTaxonomyIds;
 *   — прототипы info-блоков→ LessonBlockRepository::pluckBlockPrototypeIds.
 *
 * Стабильность важнее скорости: всё идёт через Actions и доменные
 * инварианты (settings нормализованы, file-sets синхронизированы,
 * order-ы уникальны, complexity пересчитана). На 10 000 уроков это
 * медленнее bulk-INSERT, зато каждая строка согласована.
 */
class SeedLotrLessonsCommand extends Command
{
    use LotrTextCorpus;

    protected $signature = 'lessons:seed-lotr
        {--count=100 : Number of lessons to create}
        {--min-blocks=15 : Minimum info LessonBlocks per lesson}
        {--max-blocks=25 : Maximum info LessonBlocks per lesson}
        {--min-tests=5 : Minimum tasks per lesson (common + proc)}
        {--max-tests=10 : Maximum tasks per lesson (common + proc)}
        {--keep : Do not delete existing lessons}
        {--delete-only : Only delete existing lessons (via domain) and exit; do not seed}
        {--chunk=100 : Commit progress in batches of N lessons (bigger = faster, one transaction per chunk)}
        {--force : Skip confirmation prompt}
        {--stop-file= : Path to a file; when it appears, stop gracefully after current lesson}';

    protected $description = 'Delete (optionally) and seed LOTR-themed lessons via domain actions';

    /** @var list<int> владельцы уроков (по кругу) */
    protected array $lessonOwnerIds = [];

    /** @var list<int> прототипы info-блоков (blocks.id, is_test=false, published) */
    protected array $infoPrototypeIds = [];

    /** @var array<int, bool> [leafId => hasResolver] */
    protected array $leaves = [];

    /** @var array<int, list<int>> [leafId => [taskId, ...]] */
    protected array $commonTestsByTaxonomy = [];

    /** @var list<int> */
    protected array $procTests = [];

    protected bool $stopRequested = false;
    protected ?string $stopFile = null;

    protected const BASE_LESSON_OWNER_IDS = [1, 3];

    public function __construct(
        private readonly LessonRepositoryInterface $lessons,
        private readonly LessonBlockRepositoryInterface $blocks,
        private readonly TaskRepositoryInterface $tasks,
        private readonly ProcTaskRepositoryInterface $procTasks,
        private readonly CreateLessonAction $createLesson,
        private readonly UpdateLessonAction $updateLesson,
        private readonly AddBlockAction $addBlock,
        private readonly DeleteLessonAction $deleteLesson,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->stopFile  = $this->option('stop-file') ?: null;
        $this->installSignalHandlers();

        $this->lessonOwnerIds = $this->resolveLessonOwnerIds();
        $this->info('Владельцы уроков: ' . implode(', ', $this->lessonOwnerIds));

        $count     = (int) $this->option('count');
        $minBlocks = (int) $this->option('min-blocks');
        $maxBlocks = (int) $this->option('max-blocks');
        $minTests  = (int) $this->option('min-tests');
        $maxTests  = (int) $this->option('max-tests');
        $keep      = (bool) $this->option('keep');
        $chunk     = (int) $this->option('chunk');

        if ($count < 1) {
            $this->error('--count must be >= 1');
            return self::FAILURE;
        }
        if ($chunk < 1) {
            $this->error('--chunk must be >= 1');
            return self::FAILURE;
        }
        if ($minBlocks < 1 || $maxBlocks < $minBlocks) {
            $this->error('Invalid --min-blocks / --max-blocks');
            return self::FAILURE;
        }
        if ($minTests < 0 || $maxTests < $minTests) {
            $this->error('Invalid --min-tests / --max-tests');
            return self::FAILURE;
        }

        if ($this->option('delete-only')) {
            if (!$this->option('force') && !$this->confirm(
                'This will DELETE all lessons (owners: ' . implode(', ', $this->lessonOwnerIds) . '). Continue?'
            )) {
                return self::SUCCESS;
            }
            $this->deleteAllLessons();
            return self::SUCCESS;
        }

        if (!$keep) {
            if (!$this->option('force') && !$this->confirm(
                'This will DELETE all lessons (owners: ' . implode(', ', $this->lessonOwnerIds)
                . "), then seed {$count} fresh lessons. Continue?"
            )) {
                return self::SUCCESS;
            }
            $this->deleteAllLessons();
        }

        $this->infoPrototypeIds = $this->resolveInfoPrototypeIds();
        if ($this->infoPrototypeIds === []) {
            $this->error('No info blocks found in `blocks` table (is_test = false, published = true).');
            return self::FAILURE;
        }
        $this->info('Info block prototypes: ' . count($this->infoPrototypeIds));

        $this->leaves = $this->resolveLeaves();
        if ($this->leaves === []) {
            $this->error('No leaf taxonomies found.');
            return self::FAILURE;
        }
        $this->info('Leaf taxonomies: ' . count($this->leaves));

        $this->commonTestsByTaxonomy = $this->tasks->pluckIdsGroupedByTaxonomy(array_keys($this->leaves));
        $this->procTests             = $this->procTasks->pluckAllIds();

        $commonTotal = array_sum(array_map('count', $this->commonTestsByTaxonomy));
        $procTotal   = count($this->procTests);

        $this->info("Common tasks: {$commonTotal}, proc tasks: {$procTotal}");

        if ($commonTotal === 0) {
            $this->error('Нет обычных заданий. Запустите: php artisan tests:seed-tasks --force');
            return self::FAILURE;
        }

        if ($procTotal === 0) {
            $this->warn('Процедурных заданий нет. Уроки с test_resolver получат только обычные задания.');
            $this->line('Запустите: php artisan tests:seed-proc --force');
        }

        $distribution = $this->distribute($count, count($this->leaves));
        $bar = $this->output->createProgressBar($count);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% %memory:6s%');
        $bar->start();

        $created   = 0;
        $stopped   = false;
        $leafIndex = 0;

        // Батч-транзакции: группируем createOneLesson() в одну транзакцию на
        // каждые $chunk уроков. Это снижает overhead коммитов (на 10k уроков —
        // тысячи открытий/закрытий транзакций → сотни), ускоряя сид без потери
        // доменных инвариантов: вложенные DB::transaction в Actions работают как
        // savepoints внутри нашей транзакции.
        $inChunk      = 0;
        $chunkOpen    = true;
        $chunksDone   = 0;
        $runStart     = microtime(true);
        $lastChunkAt  = $runStart;

        DB::beginTransaction();

        try {
            foreach ($this->leaves as $leafId => $hasResolver) {
                if ($this->shouldStop()) {
                    $stopped = true;
                    break;
                }

                $n = $distribution[$leafIndex++] ?? 0;
                if ($n <= 0) {
                    continue;
                }

                for ($i = 0; $i < $n; $i++) {
                    if ($this->shouldStop()) {
                        $stopped = true;
                        break 2;
                    }

                    $this->createOneLesson(
                        (int) $leafId,
                        $hasResolver,
                        $minBlocks,
                        $maxBlocks,
                        $minTests,
                        $maxTests,
                    );

                    $created++;
                    $inChunk++;
                    $bar->advance();

                    // Закрываем чанк: commit и, если ещё нужно создавать,
                    // открываем новую транзакцию.
                    if ($inChunk >= $chunk) {
                        DB::commit();
                        $chunkOpen = false;
                        $chunksDone++;
                        $committedInChunk = $inChunk;
                        $inChunk   = 0;

                        // Статус по чанку: сколько уроков всего/осталось, скорость.
                        $now      = microtime(true);
                        $chunkSec = max($now - $lastChunkAt, 0.0001);
                        $lastChunkAt = $now;
                        $elapsed  = $now - $runStart;
                        $rate     = $created > 0 ? $created / max($elapsed, 0.0001) : 0.0;
                        $remain   = max($count - $created, 0);
                        $etaSec   = $rate > 0 ? (int) round($remain / $rate) : 0;

                        $bar->clear();
                        $this->line(sprintf(
                            '  чанк #%d: +%d уроков | всего %d/%d | чанк %.1fs | %.1f ур/с | прошло %s | осталось ~%s',
                            $chunksDone,
                            $committedInChunk,
                            $created,
                            $count,
                            $chunkSec,
                            $rate,
                            $this->humanDuration((int) $elapsed),
                            $this->humanDuration($etaSec),
                        ));
                        $bar->display();

                        if ($created < $count && !$this->shouldStop()) {
                            DB::beginTransaction();
                            $chunkOpen = true;
                        }
                    }
                }
            }

            // Фиксируем остаток (если наша транзакция ещё открыта).
            if ($chunkOpen) {
                DB::commit();
                $chunkOpen = false;

                if ($inChunk > 0) {
                    $chunksDone++;
                    $bar->clear();
                    $this->line(sprintf(
                        '  чанк #%d (последний): +%d уроков | всего %d/%d',
                        $chunksDone,
                        $inChunk,
                        $created,
                        $count,
                    ));
                    $bar->display();
                }
            }
        } catch (\Throwable $e) {
            // Откатываем только текущий незакоммиченный чанк — уже
            // закоммиченные уроки остаются в БД.
            if ($chunkOpen) {
                DB::rollBack();
                $chunkOpen = false;
            }

            $bar->finish();
            $this->newLine(2);
            $this->error(sprintf(
                'Ошибка при создании урока (закоммичено чанков: %d, уроков: %d): %s',
                $chunksDone,
                $created,
                $e->getMessage(),
            ));

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        $totalSec = (int) round(microtime(true) - $runStart);

        if ($stopped) {
            $this->warn(sprintf(
                'Остановлено. Создано %d из %d уроков (чанков: %d, время: %s).',
                $created,
                $count,
                $chunksDone,
                $this->humanDuration($totalSec),
            ));
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Done. Создано %d уроков (чанков: %d, время: %s).',
            $created,
            $chunksDone,
            $this->humanDuration($totalSec),
        ));

        return self::SUCCESS;
    }

    /**
     * Человекочитаемая длительность: 45s, 3m12s, 1h05m.
     */
    private function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        if ($seconds < 3600) {
            return sprintf('%dm%02ds', intdiv($seconds, 60), $seconds % 60);
        }

        return sprintf('%dh%02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    /**
     * Создание одного урока целиком через Actions домена.
     */
    protected function createOneLesson(
        int $leafId,
        bool $hasResolver,
        int $minBlocks,
        int $maxBlocks,
        int $minTests,
        int $maxTests,
    ): void {
        $owner = new UserId($this->lessonOwnerIds[array_rand($this->lessonOwnerIds)]);

        // 1. Черновик.
        $lesson   = ($this->createLesson)($owner, new CreateLessonData(locale: 'ru'));
        $lessonId = $lesson->id();
        if ($lessonId === null) {
            throw new \LogicException('CreateLessonAction did not assign id');
        }

        // 2. Шапка + таксономия (пока не публикуем).
        ($this->updateLesson)(
            $lessonId,
            $owner,
            new UpdateLessonData(
                title: $this->pick($this->lessonTitles),
                description: $this->pick($this->lessonDescriptions),
                descriptionTouched: true,
                saved: true,
                taxonomyIds: [$leafId],
            ),
        );

        // 3. Info-блоки (own) со своими settings.
        $blockCount = random_int($minBlocks, $maxBlocks);
        for ($j = 0; $j < $blockCount; $j++) {
            $prototypeId = $this->infoPrototypeIds[array_rand($this->infoPrototypeIds)];

            ($this->addBlock)(
                $lessonId,
                $owner,
                new AddBlockData(
                    blockId: $prototypeId,
                    order: null,
                    isAdmin: true,
                    settings: $this->buildInfoBlockSettings($prototypeId),
                ),
            );
        }

        // 4. Задания: берём из пулов и привязываем к уроку.
        $testsTotal = random_int($minTests, $maxTests);
        if ($hasResolver) {
            $commonWanted = intdiv($testsTotal, 2);
            $procWanted   = $testsTotal - $commonWanted;
        } else {
            $commonWanted = $testsTotal;
            $procWanted   = 0;
        }

        $commonPool = $this->commonTestsByTaxonomy[$leafId] ?? [];
        $commonIds  = $this->sampleUnique($commonPool, $commonWanted);
        $procIds    = $this->sampleUnique($this->procTests, $procWanted);

        $order = ($this->blocks->maxOrderInLesson($lessonId) ?? -1) + 1;

        foreach (array_merge($commonIds, $procIds) as $taskId) {
            $attached = $this->blocks->attachToLesson(new LessonBlockId($taskId), $lessonId, $order++);

            // Задание исчезло (устаревший id) — вычищаем его из пулов, чтобы
            // не выбирать снова и не проверять на каждом уроке.
            if ($attached === false) {
                $this->forgetTestIdFromPools($taskId);
            }
        }

        // Пересчёт complexity: saving-хук модели Lesson читает свежий pivot.
        $this->lessons->save($this->lessons->getById($lessonId));

        // 5. Публикация.
        ($this->updateLesson)(
            $lessonId,
            $owner,
            new UpdateLessonData(published: true),
        );
    }

    /**
     * Убрать id задания из всех пулов (общие по таксономиям + proc),
     * чтобы мёртвые id не выбирались повторно.
     */
    protected function forgetTestIdFromPools(int $taskId): void
    {
        foreach ($this->commonTestsByTaxonomy as $leafId => $ids) {
            $filtered = array_values(array_filter($ids, static fn (int $id): bool => $id !== $taskId));
            if ($filtered !== $ids) {
                $this->commonTestsByTaxonomy[$leafId] = $filtered;
            }
        }

        $this->procTests = array_values(array_filter($this->procTests, static fn (int $id): bool => $id !== $taskId));
    }

    /**
     * Публичные settings info-блока с подменой контентных default-ов.
     *
     * @return array<string, mixed>
     */
    protected function buildInfoBlockSettings(int $prototypeId): array
    {
        $proto = $this->blocks->findBlockPrototype($prototypeId);
        if ($proto === null) {
            return [];
        }

        $schema    = (array) $proto['settings'];
        $newSchema = $this->fillContentDefaultsOnly($schema);

        // extractInstanceSettings сохраняет ВСЕ узлы (block1/block2/...),
        // а не только публичные ветки — иначе ломается layout-редактор.
        return $this->blocks->extractInstanceSettings($newSchema, $schema);
    }

    /**
     * Рекурсивно перезаписывает default ТОЛЬКО у контентных узлов
     * (title / summary / text / htmltext). Остальные узлы не трогаем.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    protected function fillContentDefaultsOnly(array $node): array
    {
        if (!empty($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $i => $child) {
                if (is_array($child)) {
                    $node['children'][$i] = $this->fillContentDefaultsOnly($child);
                }
            }
        }

        $key  = $node['key']  ?? null;
        $type = $node['type'] ?? null;

        if (($key === 'htmltext') || ($type === 'htmltext')) {
            $node['default'] = $this->randomHtml();
            return $node;
        }

        if ($type !== null && $type !== 'string') {
            return $node;
        }

        switch ($key) {
            case 'title':
                $node['default'] = $this->pick($this->blockTitles);
                break;
            case 'summary':
                $node['default'] = $this->pick($this->blockSummaries);
                break;
            case 'text':
                $node['default'] = $this->randomPlainText();
                break;
        }

        return $node;
    }

    // ===== Удаление уроков (через домен) =====

    protected function deleteAllLessons(): void
    {
        $ids = $this->lessons->pluckAllIds();
        $this->info(sprintf('Удаление уроков: %d...', count($ids)));

        foreach ($ids as $id) {
            ($this->deleteLesson)(new LessonId($id));
        }

        $this->cleanupOrphanFileSets();

        $this->info('Уроки удалены.');
    }

    protected function cleanupOrphanFileSets(): void
    {
        if (!Schema::hasTable('file_sets') || !Schema::hasTable('lesson_blocks')) {
            return;
        }

        try {
            $deleted = DB::table('file_sets')
                ->where('model', LessonBlock::class)
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('lesson_blocks')
                        ->whereColumn('lesson_blocks.id', 'file_sets.model_id');
                })
                ->delete();

            if ($deleted > 0) {
                $this->info("Cleaned {$deleted} orphan file_set rows.");
            }
        } catch (\Throwable $e) {
            $this->warn("FileSet cleanup skipped: {$e->getMessage()}");
        }
    }

    // ===== Пулы (read-side) =====

    /**
     * @return list<int>
     */
    protected function resolveInfoPrototypeIds(): array
    {
        $ids = $this->blocks->pluckBlockPrototypeIds(false, false);
        if ($ids === []) {
            return [];
        }

        return DB::table('blocks')
            ->whereIn('id', $ids)
            ->where('published', true)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($v): int => (int) $v)
            ->all();
    }

    /**
     * @return array<int, bool>
     */
    protected function resolveLeaves(): array
    {
        $leaves = [];

        foreach ($this->blocks->pluckLeafTaxonomyIds() as $leafId) {
            $leaves[$leafId] = $this->blocks->taxonomyHasResolver($leafId);
        }

        return $leaves;
    }

    /**
     * @return list<int>
     */
    protected function resolveLessonOwnerIds(): array
    {
        $teacherIds = $this->teacherUserIds();

        $ids = array_values(array_unique(array_merge(self::BASE_LESSON_OWNER_IDS, $teacherIds)));

        return $ids === [] ? self::BASE_LESSON_OWNER_IDS : $ids;
    }

    /**
     * @return list<int>
     */
    protected function teacherUserIds(): array
    {
        $model      = new \App\Models\User();
        $morphKey   = $model->getMorphClass();
        $roleTable  = config('permission.table_names.roles', 'roles');
        $pivotTable = config('permission.table_names.model_has_roles', 'model_has_roles');

        return DB::table($pivotTable . ' as mhr')
            ->join($roleTable . ' as r', 'r.id', '=', 'mhr.role_id')
            ->where('r.name', 'teacher')
            ->where('mhr.model_type', $morphKey)
            ->orderBy('mhr.model_id')
            ->pluck('mhr.model_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    // ===== Graceful stop =====

    protected function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            $this->warn('pcntl недоступен (Windows?) — используйте --stop-file.');
            return;
        }

        pcntl_async_signals(true);

        $onStop = function (string $label) {
            if ($this->stopRequested) {
                $this->newLine();
                $this->warn("Повторный {$label} — принудительный выход.");
                exit(130);
            }
            $this->stopRequested = true;
            $this->newLine();
            $this->warn("{$label}: завершаю текущий урок и корректно выхожу. Повторный {$label} — форс-выход.");
        };

        pcntl_signal(SIGINT, fn () => $onStop('SIGINT'));
        pcntl_signal(SIGTERM, fn () => $onStop('SIGTERM'));
    }

    protected function shouldStop(): bool
    {
        if ($this->stopRequested) {
            return true;
        }
        if ($this->stopFile && file_exists($this->stopFile)) {
            $this->stopRequested = true;
            return true;
        }
        return false;
    }

    // ===== Утилиты =====

    /**
     * @param list<int> $pool
     * @return list<int>
     */
    protected function sampleUnique(array $pool, int $want): array
    {
        if ($want <= 0 || $pool === []) {
            return [];
        }

        if ($want >= count($pool)) {
            return $pool;
        }

        $keys = (array) array_rand($pool, $want);

        return array_map(static fn ($k): int => $pool[$k], $keys);
    }

    protected function distribute(int $total, int $buckets): array
    {
        $base = intdiv($total, $buckets);
        $rem  = $total % $buckets;
        $arr  = array_fill(0, $buckets, $base);
        for ($i = 0; $i < $rem; $i++) {
            $arr[$i]++;
        }

        return $arr;
    }
}
