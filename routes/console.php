<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Удаление temp-файлов старше 24 часов — раз в сутки в 04:00
Schedule::command('files:clean-temp --hours=24')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Прунинг сирот — раз в сутки в 03:00
Schedule::command('files:prune --model=blocks')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Создание недостающих оценок для завершённых прохождений — раз в минуту.
Schedule::command('learn:make-grades')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Системная архивация устаревших прохождений — раз в сутки в 05:00.
// Критерии — в config/learn.php (переопределяются через .env).
Schedule::command('learn:archive')
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// RequestedActions: messaging — рассылка запросов приёмщикам (created → pending).
Schedule::command('requested-actions:messaging')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// RequestedActions: handle-approved — физическое выполнение approved.
Schedule::command('requested-actions:handle-approved')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// RequestedActions: retention активной ветки — раз в час (п.20).
Schedule::command('requested-actions:retain')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// RequestedActions: prune завершённой ветки — раз в сутки в 02:30.
Schedule::command('requested-actions:prune-complete')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// RequestedActions: stale-recovery зависших approved — раз в сутки в 02:00.
Schedule::command('requested-actions:stale-recovery')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
