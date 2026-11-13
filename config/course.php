<?php

declare(strict_types=1);

use App\Application\Course\Strategies\AdmissionStrategyInterface;
use App\Application\Course\Strategies\AverageLessonGradesStrategy;
use App\Application\Course\Strategies\CompletionStrategyInterface;
use App\Application\Course\Strategies\GradeStrategyInterface;
use App\Application\Course\Strategies\LessonCountCompletionStrategy;
use App\Application\Course\Strategies\SequentialAdmissionStrategy;

/**
 * Конфигурация домена курсов.
 *
 * Стратегии (§7) подключаются через интерфейс — чтобы можно было поменять
 * правило оценки/допуска, не трогая Actions и домен.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Стратегии
    |--------------------------------------------------------------------------
    |
    | grade      — как из оценок уроков получить оценку курса;
    | completion — как посчитать пройденность курса;
    | admission  — кто допущен к следующему уроку.
    |
    */

    'strategies' => [
        'grade' => AverageLessonGradesStrategy::class,
        'completion' => LessonCountCompletionStrategy::class,
        'admission' => SequentialAdmissionStrategy::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Значения по умолчанию для состава курса
    |--------------------------------------------------------------------------
    */

    'lessons' => [
        // Разрыв по умолчанию между планами уроков, в часах (TimeGap::default()).
        'time_gap' => (int) env('COURSE_LESSON_TIME_GAP', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Разрешение стратегий по имени
    |--------------------------------------------------------------------------
    */

    'contracts' => [
        GradeStrategyInterface::class => 'strategies.grade',
        CompletionStrategyInterface::class => 'strategies.completion',
        AdmissionStrategyInterface::class => 'strategies.admission',
    ],

];
