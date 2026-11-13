<?php

declare(strict_types=1);

namespace App\Application\Course\Actions;

use App\Application\Course\Context\CourseViewerContext;
use App\Application\Course\Exceptions\CourseAccessDenied;
use App\Application\Course\Exceptions\CourseNotFound;
use App\Application\Course\Ports\CourseAssignmentRepositoryInterface;
use App\Application\Course\Ports\CourseRepositoryInterface;
use App\Application\Shared\TransactionManagerInterface;
use App\Domain\Course\Entities\CourseAssignment;
use App\Domain\Course\Events\CourseAssignmentCreated;
use App\Domain\Course\Exceptions\CourseAlreadyAssigned;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\UserId;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * Назначить курс ученику (§12.1).
 *
 * Шаги (внутри транзакции):
 *  1. Проверка прав смотрящего на назначение.
 *  2. Курс существует.
 *  3. Дубль пары (course, student) → CourseAlreadyAssigned.
 *  4. Сохранить назначение.
 *  5. Опубликовать CourseAssignmentCreated.
 *
 * Транзакция открывается ЗДЕСЬ, а не в контроллере: граница атомарности —
 * часть операции, а не деталь HTTP-слоя. Событие публикуется внутри
 * транзакции, слушатель EnrollOnAssignmentCreated (ShouldQueueAfterCommit)
 * отработает только после коммита.
 *
 * Создание прохождений уроков — НЕ здесь: этим занимается слушатель.
 * Action не знает про Learn и не зависит от LearnPortInterface.
 */
final readonly class AssignCourseAction
{
    public function __construct(
        private CourseRepositoryInterface $courses,
        private CourseAssignmentRepositoryInterface $assignments,
        private TransactionManagerInterface $transaction,
        private Dispatcher $events,
    ) {}

    /**
     * @throws CourseNotFound
     * @throws CourseAccessDenied
     * @throws CourseAlreadyAssigned
     * @throws Throwable
     */
    public function execute(
        CourseViewerContext $viewer,
        CourseId $courseId,
        UserId $studentId,
        ?DateTimeImmutable $now = null,
    ): CourseAssignment {
        return $this->transaction->transactional(
            function () use ($viewer, $courseId, $studentId, $now): CourseAssignment {
                if (!$viewer->canAssign()) {
                    throw CourseAccessDenied::assign($courseId->value);
                }

                $viewerId = $viewer->userId()
                    ?? throw CourseAccessDenied::assign($courseId->value);

                // Курс должен существовать: иначе назначим прохождения
                // уроков несуществующего курса.
                $this->courses->findById($courseId)
                    ?? throw CourseNotFound::withId($courseId->value);

                if ($this->assignments->exists($courseId, $studentId)) {
                    throw CourseAlreadyAssigned::forPair($courseId->value, $studentId->value);
                }

                $assignment = $this->assignments->save(
                    CourseAssignment::create(
                        courseId: $courseId,
                        studentId: $studentId,
                        assignedBy: $viewerId,
                        now: $now ?? new DateTimeImmutable(),
                    ),
                );

                $this->events->dispatch(
                    new CourseAssignmentCreated($courseId, $studentId, $viewerId),
                );

                return $assignment;
            },
        );
    }
}
