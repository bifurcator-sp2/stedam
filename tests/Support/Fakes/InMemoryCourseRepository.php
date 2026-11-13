<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Application\Course\Ports\CourseRepositoryInterface;
use App\Domain\Course\Entities\Course;
use App\Domain\Course\Entities\CourseTheme;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\CourseThemeId;
use App\Domain\Course\ValueObjects\TaxonomyId;
use App\Domain\Course\ValueObjects\UserId;

/**
 * Репозиторий курсов в памяти — для тестов Application-слоя.
 *
 * В отличие от настоящего репозитория не лезет в БД и не разбирает состав
 * на темы/уроки: хранит агрегат как есть. Этого хватает, чтобы проверить
 * оркестрацию (права, поиск, публикацию событий).
 */
final class InMemoryCourseRepository implements CourseRepositoryInterface
{
    /** @var array<int, Course> */
    private array $items = [];

    /** @var array<int, array<int, bool>> courseId => [studentId => true] */
    private array $assignedStudentIds = [];

    private int $nextId = 1;

    private int $nextThemeId = 100;

    public int $saveCalls = 0;

    public int $deleteCalls = 0;

    /**
     * Добавить курс напрямую, минуя save() — для подготовки сцены.
     */
    public function add(Course $course): Course
    {
        if ($course->id() === null) {
            $course = $course->withId(new CourseId($this->nextId++));
        }

        $this->items[$course->id()->value] = $course;

        return $course;
    }

    public function findById(CourseId $courseId): ?Course
    {
        return $this->items[$courseId->value] ?? null;
    }

    public function save(Course $course): Course
    {
        $this->saveCalls++;

        if ($course->id() === null) {
            $course = $course->withId(new CourseId($this->nextId++));
        }

        $this->items[$course->id()->value] = $course;

        return $course;
    }

    public function delete(CourseId $courseId): void
    {
        $this->deleteCalls++;

        unset($this->items[$courseId->value]);
    }

    public function listForOwner(
        ?UserId $ownerId,
        ?TaxonomyId $taxId,
        ?string $search,
        bool $includeSystem,
        bool $allOwners = false,
    ): array {
        $result = [];

        foreach ($this->items as $course) {
            $courseOwner = $course->userId();

            if ($allOwners) {
                // Без фильтра по владельцу.
            } elseif ($ownerId === null) {
                // Без владельца — только системные (гость), либо ничего.
                if (!$includeSystem) {
                    continue;
                }

                if ($courseOwner !== null) {
                    continue;
                }
            } else {
                // Свои + системные (если включены). Чужие — мимо.
                if ($courseOwner === null) {
                    if (!$includeSystem) {
                        continue;
                    }
                } elseif (!$courseOwner->equals($ownerId)) {
                    continue;
                }
            }

            if ($taxId !== null && $course->taxId()?->value !== $taxId->value) {
                continue;
            }

            if ($search !== null && $search !== '' && mb_stripos($course->title()->value, $search) === false) {
                continue;
            }

            $result[] = $course;
        }

        return $result;
    }

    public function listForStudent(UserId $studentId): array
    {
        $result = [];

        foreach ($this->items as $course) {
            if ($this->assignedStudentIds[$course->id()->value][$studentId->value] ?? false) {
                $result[] = $course;
            }
        }

        return $result;
    }

    /**
     * Пометить курс как назначенный ученику — для listForStudent().
     */
    public function markAssignedTo(CourseId $courseId, UserId $studentId): void
    {
        $this->assignedStudentIds[$courseId->value][$studentId->value] = true;
    }

    public function typeThresholds(array $typeIds): array
    {
        return [];
    }

    /**
     * Заготовка темы с id — чтобы можно было набрать состав курса через
     * restore(), минуя двухфазное сохранение.
     */
    public function nextThemeId(): CourseThemeId
    {
        return new CourseThemeId($this->nextThemeId++);
    }

    /**
     * Создать тему с уже проставленным id (для restore курса с составом).
     *
     * @param list<\App\Domain\Course\Entities\CourseLesson> $lessons
     */
    public function theme(string $title, int $order, array $lessons = []): CourseTheme
    {
        return CourseTheme::restore($this->nextThemeId(), $title, $order, $lessons);
    }
}
