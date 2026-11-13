<?php

declare(strict_types=1);

use App\Application\Course\Services\AdmissionChecker;
use App\Application\Course\Services\CourseGradeRecalculator;
use App\Application\Course\Strategies\AverageLessonGradesStrategy;
use App\Application\Course\Strategies\LessonCountCompletionStrategy;
use App\Application\Course\Strategies\SequentialAdmissionStrategy;
use App\Domain\Course\Entities\Course;
use App\Domain\Course\Entities\CourseAssignment;
use App\Domain\Course\Entities\CourseLesson;
use App\Domain\Course\Entities\CourseTheme;
use App\Domain\Course\ValueObjects\CourseDescription;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\CourseThemeId;
use App\Domain\Course\ValueObjects\CourseTitle;
use App\Domain\Course\ValueObjects\LessonId;
use App\Domain\Course\ValueObjects\LessonTypeId;
use App\Domain\Course\ValueObjects\Locale;
use App\Domain\Course\ValueObjects\NextLessonThreshold;
use App\Domain\Course\ValueObjects\TimeGap;
use App\Domain\Course\ValueObjects\UserId;
use Tests\Support\Fakes\InMemoryLearnPort;

/**
 * @param list<array{int, int|null}> $lessons [lessonId, typeId]
 */
function serviceCourse(array $lessons, float $threshold = 0.6): Course
{
    $built = [];

    foreach ($lessons as $index => [$lessonId, $typeId]) {
        $built[] = CourseLesson::create(
            new LessonId($lessonId),
            $index + 1,
            $typeId === null ? null : new LessonTypeId($typeId),
            TimeGap::zero(),
        );
    }

    return Course::restore(
        id: new CourseId(1),
        userId: null,
        title: new CourseTitle('Курс'),
        description: new CourseDescription('Описание'),
        locale: new Locale('ru'),
        nextLessonThreshold: new NextLessonThreshold($threshold),
        taxId: null,
        themes: [CourseTheme::restore(new CourseThemeId(1), 'Тема', 1, $built)],
        updatedAt: null,
    );
}

describe('AdmissionChecker', function () {
    it('берёт оценку предыдущего урока из порта Learn', function () {
        $learn = new InMemoryLearnPort();
        $student = new UserId(5);
        $course = serviceCourse([[10, null], [20, null]]);

        $learn->setGrade($student, new LessonId(10), 0.9);

        $checker = new AdmissionChecker($learn, new SequentialAdmissionStrategy());

        expect($checker->isAdmitted($student, $course, new LessonId(20)))->toBeTrue();
    });

    it('не допускает, если предыдущий урок не оценён', function () {
        $learn = new InMemoryLearnPort();
        $course = serviceCourse([[10, null], [20, null]]);

        $checker = new AdmissionChecker($learn, new SequentialAdmissionStrategy());

        expect($checker->isAdmitted(new UserId(5), $course, new LessonId(20)))->toBeFalse();
    });

    it('применяет порог типа урока из справочника', function () {
        $learn = new InMemoryLearnPort();
        $student = new UserId(5);
        // Урок 20 — типа 3, порог типа 0.9.
        $course = serviceCourse([[10, null], [20, 3]], threshold: 0.5);

        $learn->setGrade($student, new LessonId(10), 0.7);

        $checker = new AdmissionChecker(
            $learn,
            new SequentialAdmissionStrategy(),
            typeThresholdsByTypeId: [3 => 0.9],
        );

        // 0.7 > 0.5 (курс), но 0.7 < 0.9 (тип) — отказ.
        expect($checker->isAdmitted($student, $course, new LessonId(20)))->toBeFalse();
    });

    it('строит карту допуска по всем урокам', function () {
        $learn = new InMemoryLearnPort();
        $student = new UserId(5);
        $course = serviceCourse([[10, null], [20, null], [30, null]]);

        $learn->setGrade($student, new LessonId(10), 0.9);

        $checker = new AdmissionChecker($learn, new SequentialAdmissionStrategy());

        $map = $checker->admissionMap($student, $course, [
            new LessonId(10),
            new LessonId(20),
            new LessonId(30),
        ]);

        // Первый открыт, второй — по оценке первого, третий — предыдущий (20) не сдан.
        expect($map)->toBe([10 => true, 20 => true, 30 => false]);
    });
});

describe('CourseGradeRecalculator', function () {
    it('считает grade и completion по данным порта Learn', function () {
        $learn = new InMemoryLearnPort();
        $student = new UserId(5);
        $course = serviceCourse([[10, null], [20, null], [30, null]]);

        $learn->setGrade($student, new LessonId(10), 1.0);
        $learn->setGrade($student, new LessonId(20), 0.5);

        $recalculator = new CourseGradeRecalculator(
            $learn,
            new AverageLessonGradesStrategy(),
            new LessonCountCompletionStrategy(),
        );

        $assignment = $recalculator->recalculate(
            $course,
            CourseAssignment::create(
                courseId: new CourseId(1),
                studentId: $student,
                assignedBy: new UserId(1),
                now: new DateTimeImmutable('2025-01-01 12:00:00 UTC'),
            ),
        );

        // Среднее по сданным: (1.0 + 0.5) / 2.
        expect($assignment->grade()->value)->toBe(0.75)
            // Пройдено 2 из 3.
            ->and($assignment->completion()->value)->toBe(2 / 3);
    });

    it('даёт нули, когда ничего не сдано', function () {
        $learn = new InMemoryLearnPort();
        $course = serviceCourse([[10, null], [20, null]]);

        $recalculator = new CourseGradeRecalculator(
            $learn,
            new AverageLessonGradesStrategy(),
            new LessonCountCompletionStrategy(),
        );

        $assignment = $recalculator->recalculate(
            $course,
            CourseAssignment::create(
                courseId: new CourseId(1),
                studentId: new UserId(5),
                assignedBy: new UserId(1),
                now: new DateTimeImmutable('2025-01-01 12:00:00 UTC'),
            ),
        );

        expect($assignment->grade()->value)->toBe(0.0)
            ->and($assignment->completion()->value)->toBe(0.0);
    });

    it('не меняет идентичность назначения', function () {
        $learn = new InMemoryLearnPort();
        $course = serviceCourse([[10, null]]);
        $learn->setGrade(new UserId(5), new LessonId(10), 1.0);

        $recalculator = new CourseGradeRecalculator(
            $learn,
            new AverageLessonGradesStrategy(),
            new LessonCountCompletionStrategy(),
        );

        $original = CourseAssignment::create(
            courseId: new CourseId(1),
            studentId: new UserId(5),
            assignedBy: new UserId(1),
            now: new DateTimeImmutable('2025-01-01 12:00:00 UTC'),
        );

        $updated = $recalculator->recalculate($course, $original);

        expect($updated->courseId()->value)->toBe(1)
            ->and($updated->studentId()->value)->toBe(5)
            ->and($updated->createdAt())->toEqual($original->createdAt());
    });
});
