<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\RequestedActions\Actions\HandleApprovedAction;
use App\Application\RequestedActions\Actions\SendRequestNotificationsAction;
use App\Domain\RequestedActions\Enums\RequestedActionStatus;
use App\Domain\RequestedActions\Handlers\RequestedActionStudentToTeacher;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Throwable;

/**
 * Наполнение: делает N случайных учеников учениками заданного преподавателя.
 *
 *   php artisan make:students-for --teacher_id=1 --count=20
 *
 * Подбирает до `count` пользователей с ролью `student`, которые ещё не
 * являются учениками преподавателя, и для каждого прогоняет полный процесс
 * StudentToTeacher через реальные HTTP-маршруты (с подменой auth):
 *   1. POST /api/request-action/request — от лица ученика (approve=1, teacher_id);
 *   2. агрегат переводится created → pending (эмуляция messaging-воркера —
 *      только по pending можно голосовать);
 *   3. POST /api/approve-action/approve — от лица преподавателя (approve=1);
 *   4. HandleApprovedAction физически создаёт связь teacher → student
 *      в user_relations (в проде это делает handle-воркер).
 *
 * Пользователь «подделывается» через Sanctum (Sanctum::actingAs) на время
 * вызова роута — `request()->user()` внутри контроллера возвращает нужного
 * пользователя, ровно как в запросах фронтенда. Уведомления идут в обычном
 * режиме (listeners, очередь) — как при нативном сценарии, чтобы побочно
 * накопились те же данные (notifications, requested_action_*), что и в проде.
 */
final class MakeStudentsForCommand extends Command
{
    protected $signature = 'make:students-for
                            {--teacher_id= : id преподавателя (роль teacher)}
                            {--count=20 : Сколько учеников привязать}';

    protected $description = 'Делает N случайных учеников учениками преподавателя через requested-actions (StudentToTeacher)';

    public function handle(HttpKernel $kernel): int
    {
        $teacherId = (int) $this->option('teacher_id');
        $count = (int) $this->option('count');

        if ($teacherId <= 0) {
            $this->error('Укажите --teacher_id (положительный id).');

            return self::FAILURE;
        }

        if ($count <= 0) {
            $this->error('Укажите --count (положительное число).');

            return self::FAILURE;
        }

        $teacher = User::query()->find($teacherId);

        if ($teacher === null) {
            $this->error("Преподаватель #{$teacherId} не найден.");

            return self::FAILURE;
        }

        if (! $teacher->hasRole('teacher')) {
            $this->error("Пользователь #{$teacherId} не имеет роли teacher.");

            return self::FAILURE;
        }

        $students = $this->candidateStudentIds($teacherId)
            ->shuffle()
            ->take($count)
            ->values();

        if ($students->isEmpty()) {
            $this->warn('Нет подходящих кандидатов (student, ещё не ученик преподавателя).');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Преподаватель #%d (%s): выбрано %d кандидатов.',
            $teacherId,
            $teacher->name,
            $students->count(),
        ));

        $success = 0;
        $failed = 0;

        foreach ($students as $studentId) {
            try {
                $this->attach($kernel, (int) $studentId, $teacher);
                $success++;
                $this->line(sprintf('  ✔ %d → %d', (int) $studentId, $teacherId));
            } catch (Throwable $e) {
                $failed++;
                $this->warn(sprintf('  ✖ %d: %s', (int) $studentId, $e->getMessage()));
            }
        }

        $this->info(sprintf('Готово: успешно %d, ошибок %d.', $success, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * id пользователей с ролью student, которые ещё не ученики преподавателя.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function candidateStudentIds(int $teacherId)
    {
        return DB::table('users')
            ->join('model_has_roles', function ($join): void {
                $join->on('model_has_roles.model_id', '=', 'users.id')
                    ->where('model_has_roles.model_type', User::class)
                    ->whereIn('model_has_roles.role_id', function ($query): void {
                        $query->select('id')->from('roles')->where('name', 'student');
                    });
            })
            ->whereNotExists(function ($query) use ($teacherId): void {
                $query->select(DB::raw(1))
                    ->from('user_relations')
                    ->where('user_relations.parent_user_id', $teacherId)
                    ->whereColumn('user_relations.child_user_id', 'users.id')
                    ->where('user_relations.relation', RequestedActionStudentToTeacher::RELATION_TEACHER);
            })
            ->distinct()
            ->pluck('users.id')
            ->map(static fn ($id): int => (int) $id);
    }

    /**
     * Проводит один запрос «ученик → преподаватель» через HTTP-слой.
     */
    private function attach(HttpKernel $kernel, int $studentId, User $teacher): void
    {
        $student = User::query()->find($studentId);

        if ($student === null) {
            throw new RuntimeException('пользователь не найден');
        }

        // 1. Ученик отправляет запрос преподавателю.
        $created = $this->callRoute($kernel, $student, '/api/request-action/request', [
            'action' => RequestedActionStudentToTeacher::ALIAS,
            'teacher_id' => (int) $teacher->id,
            'approve' => 1,
        ]);

        $id = (int) ($created['body']['id'] ?? 0);

        if ($id <= 0) {
            throw new RuntimeException('запрос не создан: '.json_encode($created['body'], JSON_UNESCAPED_UNICODE));
        }

        // 2. created → pending: в проде это делает messaging-воркер.
        $this->waitForPending($id);

        // 3. Преподаватель принимает запрос.
        $approved = $this->callRoute($kernel, $teacher, '/api/approve-action/approve', [
            'action' => RequestedActionStudentToTeacher::ALIAS,
            'id' => $id,
            'approve' => 1,
        ]);

        if (($approved['body']['result'] ?? null) === null) {
            throw new RuntimeException('запрос не принят: '.json_encode($approved['body'], JSON_UNESCAPED_UNICODE));
        }

        // 4. Физическое исполнение approved-агрегата (в проде — handle-воркер).
        app(HandleApprovedAction::class)->execute($id);

        if (! $this->isStudentOfTeacher($studentId, (int) $teacher->id)) {
            throw new RuntimeException("связь не создана (запрос #{$id})");
        }
    }

    /**
     * Дожидается перевода агрегата в pending: сначала сразу пробует
     * messaging-action в изоляции на этом id, затем ждёт воркер.
     */
    private function waitForPending(int $id): void
    {
        if ($this->statusOf($id) === RequestedActionStatus::Pending->value) {
            return;
        }

        // Эмуляция messaging-воркера — только на свежесозданные агрегаты,
        // чтобы не задеть чужие. execute() берёт created-батч.
        app(SendRequestNotificationsAction::class)->execute(1000);

        $deadline = microtime(true) + 15.0;

        while ($this->statusOf($id) !== RequestedActionStatus::Pending->value) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException(
                    "запрос #{$id} не перешёл в pending (статус: ".($this->statusOf($id) ?? 'не найден').'), '.
                    'проверьте requested-actions-worker',
                );
            }

            usleep(200_000);
        }
    }

    private function statusOf(int $id): ?string
    {
        $row = DB::table('requested_actions')
            ->where('id', $id)
            ->where('complete', false)
            ->first(['status', 'complete']);

        if ($row === null) {
            $row = DB::table('requested_actions')
                ->where('id', $id)
                ->where('complete', true)
                ->first(['status', 'complete']);
        }

        return $row?->status;
    }

    private function isStudentOfTeacher(int $studentId, int $teacherId): bool
    {
        return DB::table('user_relations')
            ->where('parent_user_id', $teacherId)
            ->where('child_user_id', $studentId)
            ->where('relation', RequestedActionStudentToTeacher::RELATION_TEACHER)
            ->exists();
    }

    /**
     * Вызывает HTTP-роут от лица пользователя (Sanctum::actingAs подменяет
     * auth до следующего actingAs).
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    private function callRoute(HttpKernel $kernel, User $user, string $uri, array $payload): array
    {
        Sanctum::actingAs($user);

        // JSON-тело: иначе SetLocale может сменить локаль и развалить
        // валидацию (нужен Accept: application/json, чтобы получить 422, а не редирект).
        $request = Request::create(
            $uri,
            'POST',
            [],
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );

        $response = $kernel->handle($request);

        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getContent(), true);

        // Сбрасываем auth, чтобы следующий пользователь не наследовал контекст.
        Auth::forgetGuards();

        if (! is_array($body)) {
            $body = ['raw' => (string) $response->getContent()];
        }

        if ($status >= Response::HTTP_BAD_REQUEST) {
            throw new RuntimeException(sprintf(
                '%s → HTTP %d: %s',
                $uri,
                $status,
                json_encode($body, JSON_UNESCAPED_UNICODE),
            ));
        }

        return ['status' => $status, 'body' => $body];
    }
}
