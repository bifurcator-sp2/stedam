<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Полный ресид домена «уроки и задания» одной командой.
 *
 * РЕАЛИЗАЦИЯ НА ДОМЕНЕ (DDD):
 *   Вместо TRUNCATE-хардкода все шаги идут через подкоманды, которые,
 *   в свою очередь, работают через Application-слой (Actions) и порты:
 *
 *   1. taxonomy:seed-resolvers       — листьям таксономий ставится resolver;
 *   2. lessons:seed-lotr --delete-only — удаление уроков через DeleteLessonAction
 *                                       (+чистка сирот file_sets);
 *   3. tests:seed-tasks --purge       — force-удаление обычных заданий +
 *                                       создание через CreateTaskAction;
 *   4. tests:seed-proc                — пересоздание proc-заданий через
 *                                       CreateProcTaskAction;
 *   5. lessons:seed-lotr --keep        — создание уроков через
 *                                       CreateLessonAction / AddBlockAction /
 *                                       UpdateLessonAction.
 *
 * Это медленнее, чем TRUNCATE+bulk-INSERT, но КАЖДАЯ строка проходит
 * доменные инварианты: settings нормализованы, file-sets синхронизированы,
 * order-ы уникальны, complexity пересчитана. Стабильность важнее скорости.
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
        {--proc=20 : Сколько процедурных заданий создать}
        {--chunk=100 : Батч-транзакции: один commit на N уроков (больше = быстрее, но крупнее транзакция)}';

    protected $description = 'DANGER: доменный ресид (таксономия → удалить уроки → задания → уроки)';

    public function handle(): int
    {
        $tasksCount   = (int) $this->option('tasks');
        $lessonsCount = (int) $this->option('lessons');
        $procCount    = (int) $this->option('proc');
        $chunk        = (int) $this->option('chunk');

        if ($tasksCount < 0 || $lessonsCount < 0 || $procCount < 0) {
            $this->error('Все счётчики должны быть >= 0.');
            return self::FAILURE;
        }

        if ($chunk < 1) {
            $this->error('--chunk должен быть >= 1.');
            return self::FAILURE;
        }

        $lessonsExisting = Schema::hasTable('lessons') ? DB::table('lessons')->count() : 0;
        $blocksExisting  = Schema::hasTable('lesson_blocks') ? DB::table('lesson_blocks')->count() : 0;

        $this->warn('DANGER: будут удалены ВСЕ уроки и задания (через домен).');
        $this->line(sprintf('Сейчас в БД: lessons=%d, lesson_blocks=%d.', $lessonsExisting, $blocksExisting));
        $this->newLine();
        $this->line('План:');
        $this->line('  1. taxonomy:seed-resolvers');
        $this->line('  2. lessons:seed-lotr --delete-only --force');
        $this->line("  3. tests:seed-tasks --purge --count={$tasksCount} --force");
        $this->line("  4. tests:seed-proc  --count={$procCount} --force");
        $this->line('  5. lessons:seed-lotr --keep --count=' . $lessonsCount . ' --chunk=' . $chunk);
        $this->newLine();

        if (!$this->confirm('Продолжить? Это необратимо.', false)) {
            $this->warn('Отменено.');
            return self::SUCCESS;
        }

        // --- 1. taxonomy:seed-resolvers ---
        $this->section('1/5 taxonomy:seed-resolvers');
        $code = $this->call('taxonomy:seed-resolvers', ['--force' => true]);
        if ($code !== self::SUCCESS) {
            return $this->abortStep('taxonomy:seed-resolvers', $code);
        }

        // --- 2. Удалить уроки (через домен) ---
        $this->section('2/5 lessons:seed-lotr --delete-only');
        $code = $this->call('lessons:seed-lotr', [
            '--delete-only' => true,
            '--force'       => true,
        ]);
        if ($code !== self::SUCCESS) {
            return $this->abortStep('lessons:seed-lotr --delete-only', $code);
        }

        // --- 3. tests:seed-tasks (purge + create) ---
        $this->section("3/5 tests:seed-tasks --purge --count={$tasksCount}");
        if ($tasksCount > 0) {
            $code = $this->call('tests:seed-tasks', [
                '--count' => $tasksCount,
                '--chunk' => $chunk,
                '--purge' => true,
                '--force' => true,
            ]);
            if ($code !== self::SUCCESS) {
                return $this->abortStep('tests:seed-tasks', $code);
            }
        } else {
            $this->line('Пропущено (--tasks=0).');
        }

        // --- 4. tests:seed-proc ---
        $this->section("4/5 tests:seed-proc --count={$procCount}");
        if ($procCount > 0) {
            $code = $this->call('tests:seed-proc', [
                '--count' => $procCount,
                '--force' => true,
            ]);
            if ($code !== self::SUCCESS) {
                return $this->abortStep('tests:seed-proc', $code);
            }
        } else {
            $this->line('Пропущено (--proc=0).');
        }

        // --- 5. lessons:seed-lotr --keep ---
        $this->section("5/5 lessons:seed-lotr --keep --count={$lessonsCount} --chunk={$chunk}");
        if ($lessonsCount > 0) {
            $code = $this->call('lessons:seed-lotr', [
                '--count' => $lessonsCount,
                '--chunk' => $chunk,
                '--keep'  => true,
                '--force' => true,
            ]);
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
