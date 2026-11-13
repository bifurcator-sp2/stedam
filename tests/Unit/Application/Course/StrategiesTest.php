<?php

declare(strict_types=1);

use App\Application\Course\Strategies\AverageLessonGradesStrategy;
use App\Application\Course\Strategies\LessonCountCompletionStrategy;
use App\Application\Course\Strategies\SequentialAdmissionStrategy;
use App\Domain\Course\Entities\Course;
use App\Domain\Course\Entities\CourseLesson;
use App\Domain\Course\Entities\CourseTheme;
use App\Domain\Course\ValueObjects\CourseDescription;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\CourseThemeId;
use App\Domain\Course\ValueObjects\CourseTitle;
use App\Domain\Course\ValueObjects\LessonId;
use App\Domain\Course\ValueObjects\Locale;
use App\Domain\Course\ValueObjects\NextLessonThreshold;
use App\Domain\Course\ValueObjects\TimeGap;

/**
 * Курс с уроками в одной теме. Домен — без Laravel, поэтому строим вручную.
 *
 * @param list<int> $lessonIds
 */
function strategyCourse(array $lessonIds, float $threshold = 0.6): Course
{
    $lessons = [];

    foreach ($lessonIds as $index => $lessonId) {
        $lessons[] = CourseLesson::create(
            new LessonId($lessonId),
            $index + 1,
            null,
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
        themes: [CourseTheme::restore(new CourseThemeId(1), 'Тема', 1, $lessons)],
        updatedAt: null,
    );
}

describe('SequentialAdmissionStrategy', function () {
    it('открывает первый урок всегда', function () {
        $strategy = new SequentialAdmissionStrategy();
        $course = strategyCourse([10, 20]);

        expect($strategy->isAdmitted($course, new LessonId(10), null, null))->toBeTrue();
    });

    it('не открывает второй урок без оценки предыдущего', function () {
        $strategy = new SequentialAdmissionStrategy();
        $course = strategyCourse([10, 20]);

        expect($strategy->isAdmitted($course, new LessonId(20), null, null))->toBeFalse();
    });

    it('открывает следующий урок при оценке строго выше порога', function () {
        $strategy = new SequentialAdmissionStrategy();
        $course = strategyCourse([10, 20], threshold: 0.6);

        expect($strategy->isAdmitted($course, new LessonId(20), null, 0.7))->toBeTrue();
    });

    it('не открывает при оценке РАВНОЙ порогу', function () {
        $strategy = new SequentialAdmissionStrategy();
        $course = strategyCourse([10, 20], threshold: 0.6);

        expect($strategy->isAdmitted($course, new LessonId(20), null, 0.6))->toBeFalse();
    });

    it('не открывает при оценке ниже порога', function () {
        $strategy = new SequentialAdmissionStrategy();
        $course = strategyCourse([10, 20], threshold: 0.6);

        expect($strategy->isAdmitted($course, new LessonId(20), null, 0.5))->toBeFalse();
    });

    it('берёт более строгий из порогов курса и типа', function () {
        $strategy = new SequentialAdmissionStrategy();
        $course = strategyCourse([10, 20], threshold: 0.6);

        // Порог типа 0.9 строже — именно он применяется.
        expect($strategy->isAdmitted($course, new LessonId(20), 0.9, 0.7))->toBeFalse()
            ->and($strategy->isAdmitted($course, new LessonId(20), 0.9, 0.95))->toBeTrue();
    });

    it('не ослабляет требование типа порогом курса', function () {
        $strategy = new SequentialAdmissionStrategy();
        $course = strategyCourse([10, 20], threshold: 0.3);

        // Порог типа 0.9 не должен превратиться в 0.3.
        expect($strategy->isAdmitted($course, new LessonId(20), 0.9, 0.5))->toBeFalse();
    });

    it('не допускает урок вне курса', function () {
        $strategy = new SequentialAdmissionStrategy();
        $course = strategyCourse([10, 20]);

        expect($strategy->isAdmitted($course, new LessonId(999), null, 1.0))->toBeFalse();
    });

    it('нумерует уроки через все темы подряд', function () {
        $strategy = new SequentialAdmissionStrategy();

        $theme1 = CourseTheme::restore(new CourseThemeId(1), 'Тема 1', 1, [
            CourseLesson::create(new LessonId(10), 1, null, TimeGap::zero()),
        ]);
        $theme2 = CourseTheme::restore(new CourseThemeId(2), 'Тема 2', 2, [
            CourseLesson::create(new LessonId(20), 1, null, TimeGap::zero()),
        ]);

        $course = Course::restore(
            id: new CourseId(1),
            userId: null,
            title: new CourseTitle('Курс'),
            description: new CourseDescription('Описание'),
            locale: new Locale('ru'),
            nextLessonThreshold: new NextLessonThreshold(0.6),
            taxId: null,
            themes: [$theme1, $theme2],
            updatedAt: null,
        );

        // Урок 20 идёт вторым в плоском порядке — значит требует оценку 10.
        expect($strategy->isAdmitted($course, new LessonId(20), null, null))->toBeFalse()
            ->and($strategy->isAdmitted($course, new LessonId(20), null, 0.9))->toBeTrue();
    });
});

describe('AverageLessonGradesStrategy', function () {
    it('возвращает 0 для пустого списка', function () {
        $strategy = new AverageLessonGradesStrategy();
        $course = strategyCourse([]);

        expect($strategy->grade($course, [])->value)->toBe(0.0);
    });

    it('считает среднее по оценённым урокам', function () {
        $strategy = new AverageLessonGradesStrategy();
        $course = strategyCourse([10, 20]);

        $grade = $strategy->grade($course, [10 => 1.0, 20 => 0.5]);

        expect($grade->value)->toBe(0.75);
    });

    it('игнорирует неоценённые уроки', function () {
        $strategy = new AverageLessonGradesStrategy();
        $course = strategyCourse([10, 20, 30]);

        // Несданные не тянут среднее к нулю.
        $grade = $strategy->grade($course, [10 => 1.0, 20 => null, 30 => null]);

        expect($grade->value)->toBe(1.0);
    });

    it('считает оценку 0 как сданную', function () {
        $strategy = new AverageLessonGradesStrategy();
        $course = strategyCourse([10, 20]);

        $grade = $strategy->grade($course, [10 => 1.0, 20 => 0.0]);

        expect($grade->value)->toBe(0.5);
    });
});

describe('LessonCountCompletionStrategy', function () {
    it('возвращает 0 для курса без уроков', function () {
        $strategy = new LessonCountCompletionStrategy();
        $course = strategyCourse([]);

        expect($strategy->completion($course, [])->value)->toBe(0.0);
    });

    it('считает долю оценённых уроков', function () {
        $strategy = new LessonCountCompletionStrategy();
        $course = strategyCourse([10, 20, 30, 40]);

        $completion = $strategy->completion($course, [10 => true, 20 => true, 30 => false, 40 => false]);

        expect($completion->value)->toBe(0.5);
    });

    it('даёт 1.0, когда оценены все уроки', function () {
        $strategy = new LessonCountCompletionStrategy();
        $course = strategyCourse([10, 20]);

        $completion = $strategy->completion($course, [10 => true, 20 => true]);

        expect($completion->value)->toBe(1.0)
            ->and($completion->isComplete())->toBeTrue();
    });
});
