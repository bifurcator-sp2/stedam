<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\LessonBlocks\Ports\LessonBlockRepositoryInterface;
use App\Application\Tasks\Actions\CreateTaskAction;
use App\Application\Tasks\DTOs\CreateTaskData;
use App\Application\Tasks\Ports\TaskRepositoryInterface;
use App\Domain\Lessons\ValueObjects\UserId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Сеялка обычных (не процедурных) заданий.
 *
 * РЕАЛИЗАЦИЯ НА ДОМЕНЕ (DDD):
 *   — создание — через CreateTaskAction (валидация прототипа, нормализация
 *     settings, привязка таксономий, file-sets — всё инкапсулировано);
 *   — выбор прототипов Block, листьев таксономий — через
 *     LessonBlockRepositoryInterface (read-side порт).
 *
 * Никакого вызова HTTP-контроллера: сеялка работает с Application-слоем
 * напрямую (Actions) — так же, как это сделал бы контроллер, но без
 * сериализации Request/Response.
 */
final class SeedTasksCommand extends Command
{
    protected $signature = 'tests:seed-tasks
        {--count=10 : Сколько обычных заданий создать}
        {--complexity-min=0 : Минимальная complexity (0.0)}
        {--complexity-max=1 : Максимальная complexity (1.0)}
        {--template= : Явный code блока (если не задан — случайный published is_test not is_proc)}
        {--chunk=100 : Батч-транзакции: один commit на N заданий (больше = быстрее)}
        {--purge : Удалить все существующие обычные задания перед созданием (force)}
        {--force : Не спрашивать подтверждения}';

    protected $description = 'Создаёт n обычных (не процедурных) заданий через домен CreateTaskAction';

    public function __construct(
        private readonly LessonBlockRepositoryInterface $blocks,
        private readonly CreateTaskAction $createTask,
        private readonly TaskRepositoryInterface $tasks,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $count    = (int) $this->option('count');
        $minC     = (float) $this->option('complexity-min');
        $maxC     = (float) $this->option('complexity-max');
        $template = $this->option('template') ? (string) $this->option('template') : null;
        $chunk    = (int) $this->option('chunk');

        if ($count < 1) {
            $this->error('--count должен быть >= 1.');
            return self::FAILURE;
        }

        if ($chunk < 1) {
            $this->error('--chunk должен быть >= 1.');
            return self::FAILURE;
        }

        if ($minC < 0.0 || $maxC > 1.0 || $maxC < $minC) {
            $this->error('Ожидается 0.0 <= complexity-min <= complexity-max <= 1.0.');
            return self::FAILURE;
        }

        $userIds = $this->resolveOwnerIds();
        if ($userIds === []) {
            $this->error('В таблице users нет подходящих пользователей (teacher / #1 / #3).');
            return self::FAILURE;
        }

        $blockIds = $this->resolveBlockPrototypeIds($template);
        if ($blockIds === []) {
            $this->error('Нет подходящих Block (published && is_test && !is_proc).'
                . ($template ? " Фильтр по code=\"{$template}\" не дал результатов." : ''));
            return self::FAILURE;
        }

        $leafIds = $this->blocks->pluckLeafTaxonomyIds();
        if ($leafIds === []) {
            $this->error('Нет листьев таксономий (узлов без живых детей).');
            return self::FAILURE;
        }

        if ($this->option('purge')) {
            $purged = $this->tasks->deleteAll();
            $this->info(sprintf('Удалено обычных заданий (force): %d.', $purged));
        }

        if (!$this->option('force') && !$this->confirm(
            sprintf(
                'Создать %d обычных заданий? Пользователей: %d, шаблонов Block: %d, листьев таксономий: %d.',
                $count,
                count($userIds),
                count($blockIds),
                count($leafIds),
            ),
        )) {
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% %memory:6s%');
        $bar->start();

        $created   = 0;
        $failed    = 0;
        $errors    = [];

        // Батч-транзакции: группируем создание заданий в одну транзакцию на
        // каждые $chunk штук — снижает overhead коммитов. Вложенные
        // DB::transaction внутри CreateTaskAction становятся savepoints.
        $inChunk     = 0;
        $chunkOpen   = true;
        $chunksDone  = 0;
        $runStart    = microtime(true);
        $lastChunkAt = $runStart;

        DB::beginTransaction();

        try {
            for ($i = 0; $i < $count; $i++) {
                $userId = $userIds[array_rand($userIds)];
                $actor  = new UserId($userId);

                $data = new CreateTaskData(
                    blockId: $blockIds[array_rand($blockIds)],
                    taxonomyIds: [$leafIds[array_rand($leafIds)]],
                    settings: [],
                    complexity: $this->randomComplexity($minC, $maxC),
                );

                try {
                    $task = ($this->createTask)($actor, $data, false);

                    if ($task->id() !== null) {
                        $created++;
                    } else {
                        $failed++;
                        $errors[] = 'Action вернул Task без id';
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = 'block #' . $data->blockId . ': ' . \Illuminate\Support\Str::limit($e->getMessage(), 200);
                }

                $inChunk++;
                $bar->advance();

                if ($inChunk >= $chunk) {
                    DB::commit();
                    $chunkOpen = false;
                    $chunksDone++;
                    $committed = $inChunk;
                    $inChunk   = 0;

                    $now         = microtime(true);
                    $chunkSec    = max($now - $lastChunkAt, 0.0001);
                    $lastChunkAt = $now;
                    $elapsed     = $now - $runStart;
                    $rate        = ($created + $failed) > 0 ? ($created + $failed) / max($elapsed, 0.0001) : 0.0;
                    $remain      = max($count - ($created + $failed), 0);
                    $etaSec      = $rate > 0 ? (int) round($remain / $rate) : 0;

                    $bar->clear();
                    $this->line(sprintf(
                        '  чанк #%d: +%d заданий | всего %d/%d (ok %d, err %d) | чанк %.1fs | %.1f шт/с | прошло %s | осталось ~%s',
                        $chunksDone,
                        $committed,
                        $created + $failed,
                        $count,
                        $created,
                        $failed,
                        $chunkSec,
                        $rate,
                        $this->humanDuration((int) $elapsed),
                        $this->humanDuration($etaSec),
                    ));
                    $bar->display();

                    if (($created + $failed) < $count) {
                        DB::beginTransaction();
                        $chunkOpen = true;
                    }
                }
            }

            // Фиксируем остаток (если транзакция ещё открыта).
            if ($chunkOpen) {
                DB::commit();
                $chunkOpen = false;

                if ($inChunk > 0) {
                    $chunksDone++;
                    $bar->clear();
                    $this->line(sprintf(
                        '  чанк #%d (последний): +%d заданий | всего %d/%d (ok %d, err %d)',
                        $chunksDone,
                        $inChunk,
                        $created + $failed,
                        $count,
                        $created,
                        $failed,
                    ));
                    $bar->display();
                }
            }
        } catch (\Throwable $e) {
            // Откатываем только текущий незакоммиченный чанк.
            if ($chunkOpen) {
                DB::rollBack();
                $chunkOpen = false;
            }

            $bar->finish();
            $this->newLine(2);
            $this->error(sprintf(
                'Критическая ошибка (закоммичено чанков: %d, заданий: %d): %s',
                $chunksDone,
                $created,
                $e->getMessage(),
            ));

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        $totalSec = (int) round(microtime(true) - $runStart);

        $this->info(sprintf(
            'Создано: %d, ошибок: %d (чанков: %d, время: %s).',
            $created,
            $failed,
            $chunksDone,
            $this->humanDuration($totalSec),
        ));

        if ($errors !== []) {
            $this->newLine();
            $this->warn('Ошибки (первые 10):');
            foreach (array_slice($errors, 0, 10) as $e) {
                $this->line('  - ' . $e);
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Пул владельцев: все пользователи с ролью teacher (Spatie) + 1, 3.
     *
     * @return list<int>
     */
    private function resolveOwnerIds(): array
    {
        return array_values(array_unique(array_merge([1, 3], $this->teacherIds())));
    }

    /**
     * @return array<int, int>
     */
    private function teacherIds(): array
    {
        $model      = new \App\Models\User();
        $morphKey   = $model->getMorphClass();
        $roleTable  = config('permission.table_names.roles', 'roles');
        $pivotTable = config('permission.table_names.model_has_roles', 'model_has_roles');

        return DB::table($pivotTable . ' as mhr')
            ->join($roleTable . ' as r', 'r.id', '=', 'mhr.role_id')
            ->where('r.name', 'teacher')
            ->where('mhr.model_type', $morphKey)
            ->pluck('mhr.model_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Пул прототипов: published && is_test && !is_proc.
     * Порт pluckBlockPrototypeIds не фильтрует по published — добавляем
     * фильтр по фактическому использованию: берём те, что уже опубликованы.
     * Так как write-side не знает «published», читаем через тонкий запрос
     * только ради фильтра — это read-side dev-операция.
     *
     * @return list<int>
     */
    private function resolveBlockPrototypeIds(?string $template): array
    {
        $query = DB::table('blocks')
            ->where('published', true)
            ->where('is_test', true)
            ->where('is_proc', false);

        if ($template !== null) {
            $query->where('code', $template);
        }

        return $query->orderBy('id')
            ->pluck('id')
            ->map(static fn ($v): int => (int) $v)
            ->all();
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

    private function randomComplexity(float $min, float $max): float
    {
        if ($min >= $max) {
            return round($min, 4);
        }

        $steps = 10000;
        $r     = random_int(0, $steps) / $steps;

        return round($min + ($max - $min) * $r, 4);
    }
}
