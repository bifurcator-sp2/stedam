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

            if (!$this->matchesTaxonomy($course, $taxId)) {
                continue;
            }

            if (!$this->matchesSearch($course, $search)) {
                continue;
            }

            $result[] = $course;
        }

        return $this->sortedByUpdate($result);
    }

    public function listForStudent(
        UserId $studentId,
        ?TaxonomyId $taxId = null,
        ?string $search = null,
    ): array {
        $result = [];

        foreach ($this->items as $course) {
            if (!($this->assignedStudentIds[$course->id()->value][$studentId->value] ?? false)) {
                continue;
            }

            if (!$this->matchesTaxonomy($course, $taxId) || !$this->matchesSearch($course, $search)) {
                continue;
            }

            $result[] = $course;
        }

        return $this->sortedByUpdate($result);
    }

    /**
     * Пометить курс как назначенный ученику — для listForStudent().
     */
    public function markAssignedTo(CourseId $courseId, UserId $studentId): void
    {
        $this->assignedStudentIds[$courseId->value][$studentId->value] = true;
    }

    /**
     * Таксономия с потомками: в фейке дерева нет, поэтому совпадение только
     * по самому узлу. Дерево проверяется в тестах Eloquent-репозитория.
     */
    private function matchesTaxonomy(Course $course, ?TaxonomyId $taxId): bool
    {
        return $taxId === null || $course->taxId()?->value === $taxId->value;
    }

    /**
     * Поиск без учёта регистра по названию и описанию — как ILIKE в БД.
     */
    private function matchesSearch(Course $course, ?string $search): bool
    {
        if ($search === null || trim($search) === '') {
            return true;
        }

        $needle = trim($search);

        return mb_stripos($course->title()->value, $needle) !== false
            || mb_stripos($course->description()->value, $needle) !== false;
    }

    public function typeThresholds(array $typeIds): array
    {
        return [];
    }

    /**
     * Порядок как в Eloquent-репозитории: updated_at DESC, затем id DESC.
     *
     * @param list<Course> $courses
     * @return list<Course>
     */
    private function sortedByUpdate(array $courses): array
    {
        usort($courses, static function (Course $a, Course $b): int {
            $left = $a->updatedAt()?->getTimestamp() ?? 0;
            $right = $b->updatedAt()?->getTimestamp() ?? 0;

            return [$right, $b->id()?->value ?? 0] <=> [$left, $a->id()?->value ?? 0];
        });

        return $courses;
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
