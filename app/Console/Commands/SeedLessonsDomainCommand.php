<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Полный ресид домена «уроки и задания» одной командой.
 *
 * Шаги:
 *   1. TRUNCATE lesson-таблиц (lessons, lesson_blocks и все pivots).
 *      CASCADE сносит и зависимые learns / learn_statuses / file_sets —
 *      иначе PostgreSQL не даст усечь lessons из-за FK restrictOnDelete.
 *   2. taxonomy:seed-resolvers — каждому листу таксономии ставится
 *      реально существующий test_resolver.
 *   3. tests:seed-tasks --count=N — обычные задания через
 *      TestsController::store() (нативный путь валидации + нормализации).
 *   4. tests:seed-proc --count=20 — процедурные задания с линейной
 *      complexity.
 *   5. lessons:seed-lotr --count=N --keep — уроки. --keep обязателен:
 *      уроки мы уже усекли, повторное удаление не нужно, а собственная
 *      deleteFast() команды снесла бы ещё раз file_sets.
 *
 * Владельцы заданий и уроков внутри подкоманд — все пользователи с
 * ролью teacher плюс пользователи 1 и 3 (см. resolveOwnerIds /
 * resolveLessonOwnerIds в соответствующих командах).
 *
 * Запуск:
 *   ./vendor/bin/sail artisan domain:seed-lessons
 *   ./vendor/bin/sail artisan domain:seed-lessons --tasks=500 --lessons=200
 *
 * Команда ОПАСНА: удаляет все уроки и задания. Всегда требует
 * подтверждения — флага --force нет намеренно.
 */
final class SeedLessonsDomainCommand extends Command
{
    protected $signature = 'domain:seed-lessons
        {--tasks=10000 : Сколько обычных заданий создать}
        {--lessons=10000 : Сколько уроков создать}
        {--proc=20 : Сколько процедурных заданий создать}';

    protected $description = 'DANGER: truncate уроков/заданий и полный ресид домена (таксономия → задания → уроки)';

    /**
     * Таблицы, которые нужно усечь. Порядок не важен — TRUNCATE ... CASCADE
     * сам обходит FK-граф.
     */
    private const TRUNCATE_TABLES = [
        'lessons',
        'lesson_blocks',
        'lesson_block_taxonomy',
        'lesson_lesson_block_as_test',
        'lesson_taxonomy',
    ];

    public function handle(): int
    {
        $tasksCount   = (int) $this->option('tasks');
        $lessonsCount = (int) $this->option('lessons');
        $procCount    = (int) $this->option('proc');

        if ($tasksCount < 0 || $lessonsCount < 0 || $procCount < 0) {
            $this->error('Все счётчики должны быть >= 0.');
            return self::FAILURE;
        }

        // --- Подтверждение (без --force намеренно) ---
        $lessonsExisting = Schema::hasTable('lessons') ? DB::table('lessons')->count() : 0;
        $blocksExisting  = Schema::hasTable('lesson_blocks') ? DB::table('lesson_blocks')->count() : 0;

        $this->warn('DANGER: будут удалены ВСЕ уроки и задания.');
        $this->line(sprintf('Сейчас в БД: lessons=%d, lesson_blocks=%d.', $lessonsExisting, $blocksExisting));
        $this->newLine();
        $this->line('План:');
        $this->line('  1. TRUNCATE ' . implode(', ', self::TRUNCATE_TABLES) . ' (CASCADE)');
        $this->line('  2. taxonomy:seed-resolvers');
        $this->line("  3. tests:seed-tasks --count={$tasksCount} --force");
        $this->line("  4. tests:seed-proc  --count={$procCount} --force");
        $this->line("  5. lessons:seed-lotr --count={$lessonsCount} --keep");
        $this->newLine();

        if (!$this->confirm('Продолжить? Это необратимо.', false)) {
            $this->warn('Отменено.');
            return self::SUCCESS;
        }

        // --- 1. TRUNCATE ---
        $this->section('1/5 TRUNCATE lesson-таблиц');
        $this->truncateLessonDomain();
        $this->info('OK: таблицы очищены.');

        // --- 2. taxonomy:seed-resolvers ---
        $this->section('2/5 taxonomy:seed-resolvers');
        $code = Artisan::call('taxonomy:seed-resolvers', ['--force' => true]);
        $this->output->write(Artisan::output());
        if ($code !== self::SUCCESS) {
            return $this->abortStep('taxonomy:seed-resolvers', $code);
        }

        // --- 3. tests:seed-tasks ---
        $this->section("3/5 tests:seed-tasks --count={$tasksCount}");
        if ($tasksCount > 0) {
            $code = Artisan::call('tests:seed-tasks', [
                '--count' => $tasksCount,
                '--force' => true,
            ]);
            $this->output->write(Artisan::output());
            if ($code !== self::SUCCESS) {
                return $this->abortStep('tests:seed-tasks', $code);
            }
        } else {
            $this->line('Пропущено (--tasks=0).');
        }

        // --- 4. tests:seed-proc ---
        $this->section("4/5 tests:seed-proc --count={$procCount}");
        if ($procCount > 0) {
            $code = Artisan::call('tests:seed-proc', [
                '--count' => $procCount,
                '--force' => true,
            ]);
            $this->output->write(Artisan::output());
            if ($code !== self::SUCCESS) {
                return $this->abortStep('tests:seed-proc', $code);
            }
        } else {
            $this->line('Пропущено (--proc=0).');
        }

        // --- 5. lessons:seed-lotr --keep ---
        $this->section("5/5 lessons:seed-lotr --count={$lessonsCount} --keep");
        if ($lessonsCount > 0) {
            $code = Artisan::call('lessons:seed-lotr', [
                '--count' => $lessonsCount,
                '--keep'  => true,
            ]);
            $this->output->write(Artisan::output());
            if ($code !== self::SUCCESS) {
                return $this->abortStep('lessons:seed-lotr', $code);
            }
        } else {
            $this->line('Пропущено (--lessons=0).');
        }

        $this->newLine();
        $this->info('Готово: домен «уроки и задания» пересобран.');

        return self::SUCCESS;
    }

    /**
     * TRUNCATE ... RESTART IDENTITY CASCADE по всем lesson-таблицам.
     *
     * CASCADE нужен по двум причинам:
     *   - PostgreSQL не усекает таблицу, на которую ссылаются по FK,
     *     даже с ON DELETE CASCADE — нужен именно TRUNCATE CASCADE;
     *   - learn_statuses.lesson_id имеет restrictOnDelete, и без CASCADE
     *     TRUNCATE lessons упадёт.
     *
     * Сносимые боком таблицы (learns, learn_statuses, file_sets) — это
     * ровно те данные, которые без уроков/заданий всё равно повисли бы
     * сиротами.
     */
    private function truncateLessonDomain(): void
    {
        $existing = array_values(array_filter(
            self::TRUNCATE_TABLES,
            static fn (string $t): bool => Schema::hasTable($t),
        ));

        if ($existing === []) {
            $this->warn('Ни одной из целевых таблиц нет в БД — пропускаю TRUNCATE.');
            return;
        }

        $quoted = array_map(static fn (string $t): string => '"' . str_replace('"', '""', $t) . '"', $existing);
        $sql = 'TRUNCATE TABLE ' . implode(', ', $quoted) . ' RESTART IDENTITY CASCADE';

        $this->line('SQL: ' . $sql);

        DB::statement($sql);
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->info('========================================');
        $this->info($title);
        $this->info('========================================');
    }

    private function abortStep(string $command, int $code): int
    {
        $this->newLine();
        $this->error("Шаг '{$command}' завершился с кодом {$code}. Прерываю.");
        return self::FAILURE;
    }
}
