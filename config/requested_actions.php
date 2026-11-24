<?php

declare(strict_types=1);
use App\Domain\RequestedActions\Handlers\RequestedActionStudentToTeacher;

/*
 * Конфигурация механизма «действий по запросу» (RequestedAction).
 *
 * handlers — карта alias → FQCN обработчика. Alias хранится в колонке
 * `handler` агрегата; неизвестный alias → HandlerNotFoundException.
 *
 * retention — границы жизни помесячных партиций:
 *   active_months        — N: сколько месяцев хранится активная ветка (complete=false);
 *   complete_days        — M: сколько дней хранится завершённая ветка (complete=true);
 *   lock_timeout_seconds — lock_timeout сессии retention-воркеров.
 *
 * stale — пороги stale-recovery для approved-агрегатов:
 *   not_started_hours — T1: handle() ни разу не запускался;
 *   retry_exhausted_days — T2: handle() запускался и падал.
 *
 * workers — размеры батчей воркеров.
 */

return [
    'handlers' => [
        'StudentToTeacher' => RequestedActionStudentToTeacher::class,
    ],

    'retention' => [
        'active_months' => (int) env('REQUESTED_ACTIONS_ACTIVE_MONTHS', 6),
        'complete_days' => (int) env('REQUESTED_ACTIONS_COMPLETE_DAYS', 30),
        'lock_timeout_seconds' => (int) env('REQUESTED_ACTIONS_LOCK_TIMEOUT', 5),
    ],

    'stale' => [
        'not_started_hours' => (int) env('REQUESTED_ACTIONS_STALE_T1_HOURS', 24),
        'retry_exhausted_days' => (int) env('REQUESTED_ACTIONS_STALE_T2_DAYS', 7),
    ],

    'workers' => [
        'batch_size' => (int) env('REQUESTED_ACTIONS_BATCH_SIZE', 100),
    ],
];
