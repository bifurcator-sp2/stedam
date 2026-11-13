<?php

declare(strict_types=1);

namespace App\Application\Course\Actions;

use App\Application\Course\Context\CourseViewerContext;
use App\Application\Course\Exceptions\CourseAccessDenied;
use App\Application\Course\Exceptions\CourseNotFound;
use App\Application\Course\Ports\CourseAssignmentRepositoryInterface;
use App\Application\Course\Ports\CourseRepositoryInterface;
use App\Application\Shared\TransactionManagerInterface;
use App\Domain\Course\ValueObjects\CourseId;
use Throwable;

/**
 * Удаление курса.
 *
 * Шаги (одна транзакция на всё):
 *  1. Права на редактирование: удаляет только владелец курса; системный
 *     курс (userId = null) — только admin. Морфология §13.1: canEdit.
 *  2. Курс существует.
 *  3. Для каждого назначения — удалить его и опубликовать
 *     CourseAssignmentRemoved. Слушатель почистит не начатые прохождения.
 *  4. Удалить курс.
 *
 * ПОЧЕМУ НЕ UnassignCourseAction В ЦИКЛЕ: тот Action открывает собственную
 * транзакцию. На курсе с 200 учеников получились бы 200 вложенных
 * SAVEPOINT'ов внутри одной транзакции — лишние уровни отката и мутная
 * семантика при ошибке на середине. Поэтому вызываем removeAssignment()
 * напрямую: то же действие, но уже внутри нашей транзакции.
 *
 * ПРАВИЛО ДУБЛИРУЕТСЯ с UnassignCourseAction (удаление + событие).
 * При изменении — правьте оба места.
 *
 * Иначе назначения остались бы сиротами: у них FK на курс.
 */
final readonly class DeleteCourseAction
{
    public function __construct(
        private CourseRepositoryInterface $courses,
        private CourseAssignmentRepositoryInterface $assignments,
        private UnassignCourseAction $unassign,
        private TransactionManagerInterface $transaction,
    ) {}

    /**
     * @throws CourseNotFound
     * @throws CourseAccessDenied
     * @throws Throwable
     */
    public function execute(CourseViewerContext $viewer, CourseId $courseId): void
    {
        $this->transaction->transactional(
            function () use ($viewer, $courseId): void {
                $course = $this->courses->findById($courseId)
                    ?? throw CourseNotFound::withId($courseId->value);

                if (!$viewer->canEdit($course->userId())) {
                    throw CourseAccessDenied::edit($courseId->value);
                }

                foreach ($this->assignments->listByCourse($courseId) as $assignment) {
                    $assignmentId = $assignment->id();

                    if ($assignmentId === null) {
                        // Назначение без id невозможно (пришло из БД), но
                        // молча пропускать нельзя — оставит сироту.
                        throw CourseNotFound::withId($courseId->value);
                    }

                    $this->unassign->removeAssignment(
                        $assignmentId,
                        $courseId,
                        $assignment->studentId(),
                    );
                }

                $this->courses->delete($courseId);
            },
        );
    }
}
