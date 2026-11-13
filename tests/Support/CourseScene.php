<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Course\Entities\Course;
use App\Domain\Course\Entities\CourseLesson;
use App\Domain\Course\Entities\CourseTheme;
use App\Domain\Course\ValueObjects\CourseDescription;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\CourseTitle;
use App\Domain\Course\ValueObjects\LessonId;
use App\Domain\Course\ValueObjects\Locale;
use App\Domain\Course\ValueObjects\NextLessonThreshold;
use App\Domain\Course\ValueObjects\TaxonomyId;
use App\Domain\Course\ValueObjects\TimeGap;
use App\Domain\Course\ValueObjects\UserId;
use Tests\Support\Fakes\FakeTransactionManager;
use Tests\Support\Fakes\InMemoryCourseAssignmentRepository;
use Tests\Support\Fakes\InMemoryCourseRepository;
use Tests\Support\Fakes\InMemoryLessonReader;

/**
 * Набор подмен для тестов Application-слоя курсов.
 *
 * Одна сцена = подмены + удобные фабрики курса. Экшены собираются
 * вручную в каждом тесте: так видно, какой именно зависимости лишили
 * действие, и хелпер не превращается в god-object.
 *
 * InMemoryLearnPort здесь НЕ создаётся: экшены, которые тестируются через
 * сцену (Assign/Unassign/Delete/EditHeader), от Learn не зависят. Тестам
 * стратегий нужен Learn — они создают порт сами, без сцены.
 */
final class CourseScene
{
    public FakeTransactionManager $transaction;

    public FakeDispatcher $events;

    public InMemoryCourseRepository $courses;

    public InMemoryCourseAssignmentRepository $assignments;

    public InMemoryLessonReader $lessons;

    public int $nextCourseId = 1;

    public function __construct()
    {
        $this->transaction = new FakeTransactionManager();
        $this->events = new FakeDispatcher();
        $this->courses = new InMemoryCourseRepository();
        $this->assignments = new InMemoryCourseAssignmentRepository();
        $this->lessons = new InMemoryLessonReader();
    }

    /**
     * Сохранённый курс с составом: темы и уроки с id, готов к редактированию.
     *
     * Уроки регистрируются в InMemoryLessonReader как существующие, иначе
     * EditCourseLessonAction::add откажется их добавлять.
     *
     * ВНИМАНИЕ: `$ownerId = null` означает «владелец по умолчанию» (UserId(1)),
     * а НЕ системный курс. Для системного курса (user_id = null) используйте
     * savedSystemCourse() — иначе admin-тесты молча получат курс учителя 1.
     *
     * @param array<string, list<int>> $themes название темы => id уроков
     */
    public function savedCourseWithLessons(
        array $themes,
        ?UserId $ownerId = null,
        ?TaxonomyId $taxId = null,
        float $threshold = 0.6,
    ): Course {
        return $this->buildCourse(
            themes: $themes,
            userId: $ownerId ?? new UserId(1),
            taxId: $taxId,
            threshold: $threshold,
        );
    }

    /**
     * Сохранённый СИСТЕМНЫЙ курс (user_id = null).
     *
     * Системные курсы не принадлежат никому: их видит любой авторизованный,
     * правит и создаёт только admin (§13.1). Отдельный метод — чтобы
     * «системный» и «владелец по умолчанию» нельзя было перепутать.
     *
     * @param array<string, list<int>> $themes название темы => id уроков
     */
    public function savedSystemCourse(
        array $themes,
        ?TaxonomyId $taxId = null,
        float $threshold = 0.6,
    ): Course {
        return $this->buildCourse(
            themes: $themes,
            userId: null,
            taxId: $taxId,
            threshold: $threshold,
        );
    }

    /**
     * @param array<string, list<int>> $themes
     */
    private function buildCourse(
        array $themes,
        ?UserId $userId,
        ?TaxonomyId $taxId,
        float $threshold,
    ): Course {
        $courseId = new CourseId($this->nextCourseId++);

        $builtThemes = [];
        $order = 1;

        foreach ($themes as $title => $lessonIds) {
            $builtLessons = [];
            $lessonOrder = 1;

            foreach ($lessonIds as $lessonId) {
                $this->lessons->addLesson($lessonId);
                $builtLessons[] = CourseLesson::create(
                    new LessonId($lessonId),
                    $lessonOrder++,
                    null,
                    TimeGap::zero(),
                );
            }

            $builtThemes[] = CourseTheme::restore(
                $this->courses->nextThemeId(),
                $title,
                $order++,
                $builtLessons,
            );
        }

        $course = Course::restore(
            id: $courseId,
            userId: $userId,
            title: new CourseTitle('Курс с составом'),
            description: new CourseDescription('Описание курса для теста'),
            locale: new Locale('ru'),
            nextLessonThreshold: new NextLessonThreshold($threshold),
            taxId: $taxId,
            themes: $builtThemes,
            updatedAt: null,
        );

        return $this->courses->add($course);
    }
}
