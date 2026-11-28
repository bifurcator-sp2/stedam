<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Handlers;

use App\Application\RequestedActions\Ports\RequestedActionVotesRepositoryInterface;
use App\Application\Shared\Clock;
use App\Domain\RequestedActions\DTOs\RegisterApprovalOutcome;
use App\Domain\RequestedActions\Entities\RequestedAction;
use App\Domain\RequestedActions\Enums\ApprovalResult;
use App\Domain\RequestedActions\Enums\RequestedActionStatus;
use App\Domain\RequestedActions\Enums\VoteDecision;
use App\Domain\RequestedActions\Exceptions\AlreadyStudentOfTeacherException;
use App\Domain\RequestedActions\Exceptions\AlreadyVotedException;
use App\Domain\RequestedActions\Exceptions\NotAConsumerException;
use App\Domain\RequestedActions\Exceptions\RequestedActionDomainException;
use App\Domain\RequestedActions\Exceptions\RequestedActionFatalException;
use App\Domain\RequestedActions\Exceptions\TeacherDeletedException;
use App\Domain\RequestedActions\Exceptions\UserRelationAlreadyExistsException;
use App\Domain\RequestedActions\ValueObjects\QuorumSnapshot;
use App\Domain\RequestedActions\ValueObjects\UserId;
use App\Enums\RecipientRole;
use App\Models\User;
use App\Notifications\RequestedActions\RequestedActionNotification;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * MVP-обработчик: «ученик просит преподавателя принять его в ученики».
 *
 * Alias — `StudentToTeacher`. Payload: `student_id` (инициатор),
 * `teacher_id` и обязательный `consumers = [teacher_id]`. Кворум — 1-of-1:
 * первый ответ преподавателя закрывает запрос (2.15 п.17).
 *
 * `handle()` создаёт направленную связь UserRelation
 * (parent_user_id = teacher, child_user_id = student, relation = 'teacher').
 * Идемпотентен (2.15 п.4, 2.9):
 *  - связи нет → INSERT;
 *  - связь есть и создана в рамках этого агрегата (created_at >=
 *    RequestedAction.created_at) → успех;
 *  - связь есть, но старше агрегата (создана другим путём) → FatalException.
 *
 * Ошибки доставки уведомлений не пробрасываются: queue берёт их на себя,
 * `sendXxxNotifications()` — «поставить в очередь», а не «доставить».
 */
final readonly class RequestedActionStudentToTeacher implements RequestedActionHandler
{
    public const ALIAS = 'StudentToTeacher';

    public const RELATION_TEACHER = 'teacher';

    public function __construct(
        private RequestedActionVotesRepositoryInterface $votes,
        private Clock $clock,
        private DatabaseManager $db,
    ) {}

    public function canGetRequestForm(array $arguments): bool
    {
        // Залогиненный пользователь с подтверждённым e-mail (раздел 1);
        // роль/тонкие проверки — в конкретной реализации.
        return (int) ($arguments['user_id'] ?? 0) > 0;
    }

    public function getRequestForm(array $arguments): array
    {
        $teacherId = $this->teacherId($arguments);
        $studentId = (int) ($arguments['user_id'] ?? 0);

        // Преподаватель не найден → форма без данных контрагента.
        if (! $this->userExists($teacherId)) {
            return $this->requestForm('Вы действительно хотите отправить запрос преподавателю?');
        }

        $teacher = $this->userRef($teacherId);

        // Уже ученик этого преподавателя — форма не нужна → 409 с данными
        // преподавателя (маппинг в bootstrap/app.php).
        if ($studentId > 0 && $this->hasTeacher($studentId, $teacherId)) {
            throw AlreadyStudentOfTeacherException::forTeacher($teacher['id'], $teacher['name']);
        }

        // `$user` — токен, как в текстах уведомлений RequestedAction: фронт
        // подставляет по нему ссылку на пользователя из `user`.
        return $this->requestForm('Вы действительно хотите стать учеником $user?', $teacher);
    }

    public function registerUserRequest(array $arguments): ?array
    {
        if (empty($arguments['approve'])) {
            return null;
        }

        $studentId = (int) ($arguments['user_id'] ?? 0);
        $teacherId = $this->teacherId($arguments);

        if ($studentId <= 0 || $studentId === $teacherId) {
            throw new RequestedActionDomainException('Invalid student/teacher pair');
        }

        return [
            'student_id' => $studentId,
            'teacher_id' => $teacherId,
            'consumers' => [$teacherId],
        ];
    }

    public function sendRequestNotifications(RequestedAction $action): void
    {
        $this->notify(
            $this->getRequestNotificationConsumers($action),
            fn (UserId $consumer): array => $this->getRequestNotificationMessage($consumer, $action),
        );
    }

    /**
     * @return list<UserId>
     */
    public function getRequestNotificationConsumers(RequestedAction $action): array
    {
        return array_map(
            static fn (int $id): UserId => new UserId($id),
            $action->payload()->consumers(),
        );
    }

    public function getRequestNotificationMessage(UserId $consumer, RequestedAction $action): array
    {
        // Запрос адресован преподавателю (одобряющему) — второй стороной
        // выступает ученик-инициатор.
        $student = $this->userRef($this->studentId($action));

        return [
            'type' => 'request',
            'requested_action_id' => $action->id()?->value,
            'recipient_role' => $this->recipientRole($action, $consumer)->value,
            'title' => 'Запрос на запись к преподавателю',
            'text' => 'Пользователь $user просит принять его в ученики.',
            'user' => $student,
            'approve_url' => sprintf(
                'approve-action/form?action=%s&id=%d',
                self::ALIAS,
                $action->id()?->value ?? 0,
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public function getNotificationChannels(UserId $consumer): array
    {
        // Минимум — database (2.15 п.12). mail/push добавляются здесь.
        return ['database'];
    }

    public function canGetApprovalForm(RequestedAction $action, UserId $responder, array $arguments): bool
    {
        return $action->isPending() && $action->payload()->isConsumer($responder->value);
    }

    public function getApprovalForm(RequestedAction $action, UserId $responder, array $arguments): array
    {
        if (! $action->payload()->isConsumer($responder->value)) {
            throw NotAConsumerException::forUser($responder->value, $action->id()?->value ?? 0);
        }

        $studentId = (int) $action->payload()->get('student_id', 0);
        $name = $this->fullNameOf($studentId);

        return [
            'title' => ['type' => 'string', 'value' => 'Приём ученика.'],
            'text' => [
                'type' => 'string',
                'value' => $name === null
                    ? 'Вы действительно хотите принять ученика?'
                    : "Вы действительно хотите принять на обучение {$name}?",
            ],
            'approve' => ['type' => 'boolean', 'value' => false],
        ];
    }

    public function registerApproval(
        RequestedAction $action,
        UserId $responder,
        array $arguments,
    ): RegisterApprovalOutcome {
        if ($action->status() !== RequestedActionStatus::Pending) {
            return new RegisterApprovalOutcome($action, ApprovalResult::CLOSED);
        }

        $actionId = $action->id()?->value ?? 0;

        if (! $action->payload()->isConsumer($responder->value)) {
            throw NotAConsumerException::forUser($responder->value, $actionId);
        }

        $decision = ! empty($arguments['approve'])
            ? VoteDecision::Accepted
            : VoteDecision::Declined;

        if (! $this->votes->record($action->ref(), $responder, $decision)) {
            throw AlreadyVotedException::forUser($responder->value, $actionId);
        }

        $total = count($action->payload()->consumers());
        $snapshot = $this->votes->snapshot($action->ref(), $total, $this->quorumSize($action));
        $now = $this->clock->now();

        if ($snapshot->isSatisfied() && $this->handleApproved($snapshot)) {
            return new RegisterApprovalOutcome($action->markApproved($now), ApprovalResult::ACCEPTED);
        }

        if ($snapshot->isImpossible()) {
            return new RegisterApprovalOutcome($this->reject($action), ApprovalResult::DECLINED);
        }

        return new RegisterApprovalOutcome($action, ApprovalResult::WAITING);
    }

    public function quorumSize(RequestedAction $action): int
    {
        return 1;
    }

    public function handleApproved(QuorumSnapshot $snapshot): bool
    {
        return true;
    }

    public function reject(RequestedAction $action): RequestedAction
    {
        return $action->reject($this->clock->now());
    }

    public function sendApprovalNotifications(RequestedAction $action): void
    {
        $this->notify(
            $this->initiator($action),
            fn (UserId $consumer): array => $this->getApprovedNotificationMessage($consumer, $action),
        );
    }

    public function getApprovedNotificationMessage(UserId $consumer, RequestedAction $action): array
    {
        // Уведомление идёт инициатору-ученику; $user — принявший преподаватель
        // (единственный приёмщик в MVP 1-of-1).
        $teacher = $this->userRef($this->approverId($action));

        return [
            'type' => 'approved',
            'requested_action_id' => $action->id()?->value,
            'recipient_role' => $this->recipientRole($action, $consumer)->value,
            'title' => 'Запрос принят',
            'text' => 'Преподаватель $user принял ваш запрос на запись.',
            'user' => $teacher,
        ];
    }

    public function sendRejectionNotifications(RequestedAction $action): void
    {
        $this->notify(
            $this->initiator($action),
            fn (UserId $consumer): array => $this->getRejectedNotificationMessage($consumer, $action),
        );
    }

    public function getRejectedNotificationMessage(UserId $consumer, RequestedAction $action): array
    {
        // Уведомление идёт инициатору-ученику; $user — отклонивший преподаватель.
        $teacher = $this->userRef($this->approverId($action));

        return [
            'type' => 'rejected',
            'requested_action_id' => $action->id()?->value,
            'recipient_role' => $this->recipientRole($action, $consumer)->value,
            'title' => 'Запрос отклонён',
            'text' => 'Преподаватель $user отклонил ваш запрос на запись.',
            'user' => $teacher,
        ];
    }

    public function sendDoneNotifications(RequestedAction $action): void
    {
        $this->notify(
            $this->bothSides($action),
            fn (UserId $consumer): array => $this->getDoneNotificationMessage($consumer, $action),
        );
    }

    public function getDoneNotificationMessage(UserId $consumer, RequestedAction $action): array
    {
        // Текст зависит от стороны: та же пара «ученик — преподаватель», но для
        // инициатора-ученика контрагент — преподаватель, для одобряющего —
        // ученик. `$user` — токен для фронта, подставляется ссылкой с данными.
        $message = match ($this->recipientRole($action, $consumer)) {
            RecipientRole::Initiator => [
                'title' => 'Вас приняли в ученики',
                'text' => 'Теперь вы ученик $user.',
                'user' => $this->userRef($this->approverId($action)),
            ],
            RecipientRole::Consumer => [
                'title' => 'У вас новый ученик',
                'text' => '$user стал вашим учеником.',
                'user' => $this->userRef($this->studentId($action)),
            ],
        };

        return [
            'type' => 'done',
            'requested_action_id' => $action->id()?->value,
            'recipient_role' => $this->recipientRole($action, $consumer)->value,
            ...$message,
        ];
    }

    public function sendErrorNotifications(RequestedAction $action): void
    {
        $this->notify(
            $this->bothSides($action),
            fn (UserId $consumer): array => $this->getErrorNotificationMessage($consumer, $action),
        );
    }

    public function getErrorNotificationMessage(UserId $consumer, RequestedAction $action): array
    {
        return [
            'type' => 'error',
            'requested_action_id' => $action->id()?->value,
            'recipient_role' => $this->recipientRole($action, $consumer)->value,
            'title' => 'Ошибка запроса',
            'text' => sprintf(
                'Запрос завершился ошибкой: %s',
                $action->errorCode() ?? 'unknown',
            ),
        ];
    }

    public function handle(RequestedAction $action): void
    {
        $studentId = (int) $action->payload()->get('student_id', 0);
        $teacherId = (int) $action->payload()->get('teacher_id', 0);

        if ($studentId <= 0 || $teacherId <= 0) {
            throw new RequestedActionFatalException(
                'Payload must contain student_id and teacher_id',
            );
        }

        if (! $this->userExists($teacherId)) {
            throw TeacherDeletedException::withId($teacherId);
        }

        $existing = $this->db->connection()
            ->table('user_relations')
            ->where('parent_user_id', $teacherId)
            ->where('child_user_id', $studentId)
            ->where('relation', self::RELATION_TEACHER)
            ->first(['id', 'created_at']);

        if ($existing !== null) {
            // Идемпотентность (2.9): связь, созданная не раньше агрегата, —
            // результат этого же процесса (повторный вызов handle()).
            if ($action->createdAt() !== null
                && new DateTimeImmutable((string) $existing->created_at) < $action->createdAt()
            ) {
                throw UserRelationAlreadyExistsException::forPair($teacherId, $studentId);
            }

            return;
        }

        $now = $this->clock->now();

        $this->db->connection()->table('user_relations')->insert([
            'parent_user_id' => $teacherId,
            'child_user_id' => $studentId,
            'relation' => self::RELATION_TEACHER,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function userExists(int $userId): bool
    {
        return $this->db->connection()->table('users')->where('id', $userId)->exists();
    }

    // === Внутренние помощники ===

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function teacherId(array $arguments): int
    {
        $teacherId = (int) ($arguments['teacher_id'] ?? 0);

        if ($teacherId <= 0) {
            throw new RequestedActionDomainException('teacher_id is required');
        }

        return $teacherId;
    }

    /**
     * Описание формы запроса. `user` — расшифровка токена `$user` из `text`
     * (как в текстах уведомлений); без токена в тексте не отдаётся.
     *
     * @param  array{id: int, name: string}|null  $user
     * @return array<string, mixed>
     */
    private function requestForm(string $text, ?array $user = null): array
    {
        return [
            'title' => ['type' => 'string', 'value' => 'Запись к преподавателю.'],
            'text' => ['type' => 'string', 'value' => $text],
            'approve' => ['type' => 'boolean', 'value' => false],
        ] + ($user === null ? [] : ['user' => $user]);
    }

    /**
     * Есть ли направленная связь «teacherId — преподаватель studentId».
     */
    private function hasTeacher(int $studentId, int $teacherId): bool
    {
        return $this->db->connection()
            ->table('user_relations')
            ->where('parent_user_id', $teacherId)
            ->where('child_user_id', $studentId)
            ->where('relation', self::RELATION_TEACHER)
            ->exists();
    }

    /**
     * Полное имя (ФИО) пользователя по данным профиля.
     */
    private function fullNameOf(int $userId): ?string    {
        if ($userId <= 0) {
            return null;
        }

        $row = $this->db->connection()->table('users')
            ->leftJoin('user_data', 'user_data.user_id', '=', 'users.id')
            ->where('users.id', $userId)
            ->first(['users.name', 'user_data.first_name', 'user_data.last_name', 'user_data.middle_name']);

        if ($row === null) {
            return null;
        }

        $parts = array_filter([
            $row->last_name ?? null,
            $row->first_name ?? null,
            $row->middle_name ?? null,
        ]);

        return $parts !== [] ? implode(' ', $parts) : ((string) ($row->name ?? '') ?: null);
    }

    /**
     * Имя пользователя для текста уведомления; для пользователя без профиля —
     * короткое имя.
     */
    private function displayNameOf(int $userId): ?string
    {
        $fullName = $this->fullNameOf($userId);

        if ($fullName !== null) {
            return $fullName;
        }

        $name = $this->db->connection()->table('users')->where('id', $userId)->value('name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Инициатор запроса.
     *
     * @return list<UserId>
     */
    private function initiator(RequestedAction $action): array
    {
        return [new UserId((int) $action->payload()->get('student_id', $action->userId()->value))];
    }

    /**
     * Обе стороны действия: инициатор и все приёмщики.
     *
     * @return list<UserId>
     */
    private function bothSides(RequestedAction $action): array
    {
        $ids = $action->payload()->consumers();
        $ids[] = (int) $action->payload()->get('student_id', $action->userId()->value);
        $ids[] = $action->userId()->value;

        return array_map(
            static fn (int $id): UserId => new UserId($id),
            array_values(array_unique($ids)),
        );
    }

    /**
     * Сторона получателя уведомления. Инициатор — `requested_actions.user_id`
     * (для этого handler'а совпадает с `payload.student_id`), все прочие
     * получатели — одобряющие.
     */
    private function recipientRole(RequestedAction $action, UserId $consumer): RecipientRole
    {
        return $consumer->value === $action->userId()->value
            ? RecipientRole::Initiator
            : RecipientRole::Consumer;
    }

    /**
     * Id ученика-инициатора: `payload.student_id`, fallback — `user_id`.
     */
    private function studentId(RequestedAction $action): int
    {
        return (int) $action->payload()->get('student_id', $action->userId()->value);
    }

    /**
     * Id преподавателя: `payload.teacher_id`, fallback — единственный консьюмер.
     */
    private function approverId(RequestedAction $action): int
    {
        $consumers = $action->payload()->consumers();

        return (int) $action->payload()->get('teacher_id', $consumers[0] ?? 0);
    }

    /**
     * Данные контрагента для фронта: токен `$user` в `text` подставляется
     * ссылкой «id — ФИО».
     *
     * @return array{id: int, name: string}
     */
    private function userRef(int $userId): array
    {
        return [
            'id' => $userId,
            'name' => $this->displayNameOf($userId) ?? "#{$userId}",
        ];
    }

    /**
     * Поставить уведомления в очередь. Ошибки доставки — забота queue:
     * сообщение уже поставлено, handler падать не должен (2.11).
     *
     * @param  list<UserId>  $consumers
     * @param  callable(UserId): array<string, mixed>  $messageBuilder
     */
    private function notify(array $consumers, callable $messageBuilder): void
    {
        foreach ($consumers as $consumer) {
            $user = User::query()->find($consumer->value);

            if ($user === null) {
                continue;
            }

            $user->notify(new RequestedActionNotification(
                payload: $messageBuilder($consumer),
                via: $this->getNotificationChannels($consumer),
            ));
        }
    }
}
