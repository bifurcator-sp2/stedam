<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\LessonBlocks\Ports\LessonBlockRepositoryInterface;
use App\Application\TasksProc\Actions\CreateProcTaskAction;
use App\Application\TasksProc\DTOs\CreateProcTaskData;
use App\Application\TasksProc\Ports\ProcTaskRepositoryInterface;
use App\Domain\Lessons\ValueObjects\UserId;
use App\Domain\TasksProc\Entities\ProcTask;
use App\Domain\TasksProc\ValueObjects\ProcTaskId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Приводит БД к состоянию, при котором существуют ровно N процедурных
 * заданий (is_proc = true) с линейно распределённой сложностью.
 *
 * РЕАЛИЗАЦИЯ НА ДОМЕНЕ (DDD):
 *   — удаление — через ProcTaskRepositoryInterface::delete по id;
 *   — создание — через CreateProcTaskAction (валютный путь через
 *     домен ProcTask + репозиторий, со всеми инвариантами:
 *     «создавать может только admin», proc-прототип, нормализация settings);
 *   — выбор proc-шаблона и пул id — через порты LessonBlockRepository /
 *     ProcTaskRepository.
 *
 * Никакого DB::table-хардкода для записи. Стабильность важнее скорости:
 * на N=20 это доли секунды, зато данные всегда консистентны.
 *
 * Идемпотентность:
 *   Повторный запуск пересоздаёт задания — id меняются. На них уже могут
 *   ссылаться уроки через lesson_lesson_block_as_test; поэтому удаление
 *   идёт forceDelete с каскадным обрывом pivot по FK. Запускайте до
 *   seeding уроков (или вместе с domain:seed-lessons).
 */
final class SeedProcTestsCommand extends Command
{
    protected $signature = 'tests:seed-proc
        {--count=20 : Сколько процедурных заданий должно быть в БД}
        {--min=0 : Минимальная сложность включительно}
        {--max=1 : Максимальная сложность включительно}
        {--template=text_image : code шаблона Block для proc-задания}
        {--admin-id=1 : ID пользователя-админа, от имени которого создаются задания}
        {--submit : Сохранять шаблон с published = true}
        {--force : Не спрашивать подтверждения}';

    protected $description = 'Создаёт фиксированный набор процедурных заданий (is_proc) с линейной сложностью (через домен)';

    public function __construct(
        private readonly ProcTaskRepositoryInterface $procTasks,
        private readonly LessonBlockRepositoryInterface $blocks,
        private readonly CreateProcTaskAction $createProcTask,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $count    = (int) $this->option('count');
        $min      = (float) $this->option('min');
        $max      = (float) $this->option('max');
        $template = (string) $this->option('template');
        $adminId  = (int) $this->option('admin-id');

        if ($count < 1) {
            $this->error('--count must be >= 1.');
            return self::FAILURE;
        }

        if ($min < 0.0 || $max > 1.0 || $max < $min) {
            $this->error('Ожидается 0.0 <= min <= max <= 1.0.');
            return self::FAILURE;
        }

        if ($adminId <= 0) {
            $this->error('--admin-id должен быть положительным.');
            return self::FAILURE;
        }

        if (!$this->option('force') && !$this->confirm(
            sprintf(
                'Все существующие is_proc задания будут УДАЛЕНЫ (force), затем создано ровно %d шт. от имени пользователя #%d. Continue?',
                $count,
                $adminId,
            ),
        )) {
            return self::SUCCESS;
        }

        // --- 0. Найти proc-шаблон Block ---
        $prototypeId = $this->resolveProcPrototypeId($template);
        if ($prototypeId === null) {
            $this->error(sprintf(
                'Нет Block-прототипа с code="%s" и is_proc=true (и is_test=true). Создайте его или укажите другой --template.',
                $template,
            ));
            return self::FAILURE;
        }

        // --- 1. Удалить существующие proc-задания (через домен) ---
        $purged = $this->purgeAllProcTasks();
        $this->line(sprintf('Удалено is_proc заданий: %d.', $purged));

        // --- 2. Создать N новых с линейной complexity ---
        $complexities = $this->linearSeries($count, $min, $max);
        $created      = 0;

        $bar = $this->output->createProgressBar($count);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%');
        $bar->start();

        DB::transaction(function () use ($complexities, $prototypeId, $adminId, &$created, $bar) {
            $actor = new UserId($adminId);

            foreach ($complexities as $complexity) {
                $data = new CreateProcTaskData(
                    blockId: $prototypeId,
                    settings: [],
                    complexity: $complexity,
                );

                $task = ($this->createProcTask)($actor, $data, true);

                if ($task->id() !== null) {
                    $created++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info(sprintf('Готово: удалено %d, создано %d.', $purged, $created));
        $this->table(
            ['#', 'complexity'],
            array_map(
                static fn (int $i): array => [$i + 1, number_format($complexities[$i], 2)],
                array_keys($complexities),
            ),
        );

        if ($this->option('submit')) {
            $this->publishPrototype($prototypeId);
        }

        $this->line('');
        $this->line('Дальше: php artisan domain:seed-lessons  (или lessons:seed-lotr --keep)');

        return self::SUCCESS;
    }

    /**
     * proc-шаблон: Block с code + is_proc=true (+ is_test=true).
     * Возвращает id или null.
     */
    private function resolveProcPrototypeId(string $code): ?int
    {
        $blockId = DB::table('blocks')
            ->where('code', $code)
            ->where('is_proc', true)
            ->where('is_test', true)
            ->orderByDesc('id')
            ->value('id');

        return $blockId === null ? null : (int) $blockId;
    }

    /**
     * Удаляет все proc-задания через доменный порт.
     * Возвращает число удалённых.
     */
    private function purgeAllProcTasks(): int
    {
        $ids     = $this->procTasks->pluckAllIds();
        $deleted = 0;

        foreach ($ids as $id) {
            $this->procTasks->delete(new ProcTaskId($id));
            $deleted++;
        }

        return $deleted;
    }

    private function publishPrototype(int $prototypeId): void
    {
        DB::table('blocks')
            ->where('id', $prototypeId)
            ->update(['published' => true, 'updated_at' => now()]);

        $this->line("Шаблон Block #{$prototypeId} опубликован.");
    }

    /**
     * Линейный ряд сложностей: count центров равных интервалов на [min, max].
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
