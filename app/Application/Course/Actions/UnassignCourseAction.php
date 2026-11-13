<?php

declare(strict_types=1);

namespace App\Application\Course\Actions;

use App\Application\Course\Context\CourseViewerContext;
use App\Application\Course\Exceptions\CourseAccessDenied;
use App\Application\Course\Exceptions\CourseNotFound;
use App\Application\Course\Ports\CourseAssignmentRepositoryInterface;
use App\Application\Course\Ports\CourseRepositoryInterface;
use App\Application\Shared\TransactionManagerInterface;
use App\Domain\Course\Events\CourseAssignmentRemoved;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\CourseAssignmentId;
use App\Domain\Course\ValueObjects\UserId;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * Снять назначение курса (§12.3).
 *
 * Удаляет назначение и публикует CourseAssignmentRemoved. Прохождения
 * уроков удаляет слушатель UnenrollOnAssignmentRemoved — и только
 * НЕ НАЧАТЫЕ (начатые и сданные — часть истории обучения).
 *
 * ПРАВИЛО ДУБЛИРУЕТСЯ в DeleteCourseAction. Причина: DeleteCourseAction
 * удаляет курс пачкой и обязан делать это ОДНОЙ транзакцией. Если бы он
 * звал этот Action в цикле, получились бы вложенные транзакции (SAVEPOINT)
 * на каждого ученика. Поэтому там та же логика — удаление назначения плюс
 * событие — исполняется inline. При изменении правил правьте оба места.
 */
final readonly class UnassignCourseAction
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
     * @throws Throwable
     */
    public function execute(
        CourseViewerContext $viewer,
        CourseId $courseId,
        UserId $studentId,
    ): void {
        $this->transaction->transactional(
            function () use ($viewer, $courseId, $studentId): void {
                if (!$viewer->canAssign()) {
                    throw CourseAccessDenied::assign($courseId->value);
                }

                $this->courses->findById($courseId)
                    ?? throw CourseNotFound::withId($courseId->value);

                $assignment = $this->assignments->findByCourseAndStudent($courseId, $studentId)
                    ?? throw CourseNotFound::withId($courseId->value);

                $assignmentId = $assignment->id()
                    ?? throw CourseNotFound::withId($courseId->value);

                $this->removeAssignment($assignmentId, $courseId, $studentId);
            },
        );
    }

    /**
     * Ядро операции: удалить назначение и объявить об этом.
     *
     * Вынесено отдельным методом, чтобы DeleteCourseAction мог выполнить
     * то же самое БЕЗ открытия новой транзакции.
     */
    public function removeAssignment(
        CourseAssignmentId $assignmentId,
        CourseId $courseId,
        UserId $studentId,
    ): void {
        $this->assignments->delete($assignmentId);

        $this->events->dispatch(
            new CourseAssignmentRemoved($courseId, $studentId),
        );
    }
}
