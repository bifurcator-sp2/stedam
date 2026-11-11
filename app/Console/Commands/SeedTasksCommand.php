<?php
// app/Console/Commands/SeedTasksCommand.php

namespace App\Console\Commands;

use App\Http\Controllers\Api\TestsController;
use App\Models\Block;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Сеялка обычных (не процедурных) заданий.
 *
 * Задание — это LessonBlock с lesson_id = NULL и is_proc = false,
 * привязанный к таксономии-листу через lesson_block_taxonomy.
 * Такие же задания использует SeedLotrLessonsCommand (пул
 * loadCommonTestsByLeaf) при генерации уроков.
 *
 * Механика: для каждого задания
 *   1. случайный пользователь из БД;
 *   2. случайный Block (published = true, is_test = true, is_proc = false);
 *   3. случайный лист таксономии;
 *   4. complexity = random 0.0 … 1.0;
 *   5. POST в TestsController::store() с синтетическим Request,
 *      у которого userResolver возвращает выбранного пользователя.
 *
 * settings в запрос НЕ передаются: store() прогонит пустой settings через
 * SettingsNormalizer::extractPublicDefaults() и подставит default-значения
 * из схемы самого блока.
 *
 * Запуск:
 *   ./vendor/bin/sail artisan tests:seed-tasks
 *   ./vendor/bin/sail artisan tests:seed-tasks --count=25
 *   ./vendor/bin/sail artisan tests:seed-tasks --count=10 --complexity-min=0.2 --complexity-max=0.8
 */
class SeedTasksCommand extends Command
{
    protected $signature = 'tests:seed-tasks
        {--count=10 : Сколько обычных заданий создать}
        {--complexity-min=0 : Минимальная complexity (0.0)}
        {--complexity-max=1 : Максимальная complexity (1.0)}
        {--template= : Явный code блока (если не задан — случайный published is_test not is_proc)}
        {--force : Не спрашивать подтверждения}';

    protected $description = 'Создаёт n обычных (не процедурных) заданий через TestsController::store()';

    public function handle(): int
    {
        $count     = (int) $this->option('count');
        $minC      = (float) $this->option('complexity-min');
        $maxC      = (float) $this->option('complexity-max');
        $template  = $this->option('template') ? (string) $this->option('template') : null;

        if ($count < 1) {
            $this->error('--count должен быть >= 1.');
            return self::FAILURE;
        }

        if ($minC < 0.0 || $maxC > 1.0 || $maxC < $minC) {
            $this->error('Ожидается 0.0 <= complexity-min <= complexity-max <= 1.0.');
            return self::FAILURE;
        }

        // --- Пул пользователей: все учителя + 1, 3 ---
        $userIds = $this->resolveOwnerIds();
        if (empty($userIds)) {
            $this->error('В таблице users нет пользователей.');
            return self::FAILURE;
        }

        // --- Пул шаблонов Block ---
        $blockQuery = Block::query()
            ->where('published', true)
            ->where('is_test', true)
            ->where('is_proc', false);

        if ($template !== null) {
            $blockQuery->where('code', $template);
        }

        $blockIds = $blockQuery->pluck('id')->all();
        if (empty($blockIds)) {
            $this->error('Нет подходящих Block (published && is_test && !is_proc).'
                . ($template ? " Фильтр по code=\"{$template}\" не дал результатов." : ''));
            return self::FAILURE;
        }

        // --- Пул листьев таксономий ---
        $leafIds = $this->leafTaxonomyIds();
        if (empty($leafIds)) {
            $this->error('Нет листьев таксономий (узлов без живых детей).');
            return self::FAILURE;
        }

        if (!$this->option('force') && !$this->confirm(
            sprintf(
                'Создать %d обычных заданий? Пользователей: %d, шаблонов Block: %d, листьев таксономий: %d.',
                $count,
                count($userIds),
                count($blockIds),
                count($leafIds),
            )
        )) {
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $created = 0;
        $failed  = 0;
        $errors  = [];

        for ($i = 0; $i < $count; $i++) {
            $userId  = $userIds[array_rand($userIds)];
            $blockId = $blockIds[array_rand($blockIds)];
            $leafId  = $leafIds[array_rand($leafIds)];
            $complexity = $this->randomComplexity($minC, $maxC);

            $ok = $this->createTaskViaController($userId, $blockId, $leafId, $complexity, $error);

            if ($ok) {
                $created++;
            } else {
                $failed++;
                $errors[] = $error;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf('Создано: %d, ошибок: %d.', $created, $failed));

        if (!empty($errors)) {
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
     * Дубликаты убираются.
     *
     * @return array<int, int>
     */
    protected function resolveOwnerIds(): array
    {
        return array_values(array_unique(array_merge([1, 3], $this->teacherIds())));
    }

    /**
     * ID пользователей с ролью teacher. Фильтр по morph-алиасу берём
     * из самой модели User — так запрос не ломается, если в проекте
     * включат Relation::enforceMorphMap().
     *
     * @return array<int, int>
     */
    private function teacherIds(): array
    {
        $model = new User();
        $morphKey = $model->getMorphClass();
        $roleTable = config('permission.table_names.roles', 'roles');
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
     * Один вызов TestsController::store() с синтетическим Request.
     *
     * @return bool true, если задание создано (HTTP 201)
     */
    protected function createTaskViaController(
        int $userId,
        int $blockId,
        int $leafId,
        float $complexity,
        ?string &$error = null,
    ): bool {
        $user = User::find($userId);
        if ($user === null) {
            $error = "Пользователь #{$userId} не найден";
            return false;
        }

        $request = Request::create(
            uri: '/api/tests',
            method: 'POST',
            parameters: [
                'block_id'     => $blockId,
                'taxonomy_ids' => [$leafId],
                'complexity'   => $complexity,
                'is_proc'      => false,
                'is_test'      => true,
            ],
        );

        // Подсовываем userResolver — контроллер берёт $request->user().
        $request->setUserResolver(static fn () => $user);

        try {
            $response = app(TestsController::class)->store($request);
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: $e->getMessage();
            $error = "block #{$blockId} / tax #{$leafId}: validation: {$msg}";
            return false;
        } catch (\Throwable $e) {
            $error = "block #{$blockId} / tax #{$leafId}: " . Str::limit($e->getMessage(), 200);
            return false;
        }

        if ($response->getStatusCode() !== 201) {
            $error = "block #{$blockId} / tax #{$leafId}: HTTP {$response->getStatusCode()} "
                . Str::limit((string) $response->getContent(), 200);
            return false;
        }

        return true;
    }

    /**
     * Листья таксономий (без живых детей). Владелец не важен.
     *
     * @return array<int, int>
     */
    protected function leafTaxonomyIds(): array
    {
        return DB::table('taxonomies')
            ->whereNull('deleted_at')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('taxonomies as t_child')
                    ->whereColumn('t_child.parent_id', 'taxonomies.id')
                    ->whereNull('t_child.deleted_at');
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Случайная complexity в [min, max], округлённая до 4 знаков.
     */
    protected function randomComplexity(float $min, float $max): float
    {
        if ($min >= $max) {
            return round($min, 4);
        }

        // random_int даёт равномерное распределение в целых — масштабируем.
        $steps = 10000;
        $r = random_int(0, $steps) / $steps;

        return round($min + ($max - $min) * $r, 4);
    }
}
