<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Удаление temp-файлов старше 24 часов — раз в сутки в 04:00
\Illuminate\Support\Facades\Schedule::command('files:clean-temp --hours=24')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Прунинг сирот — раз в сутки в 03:00
\Illuminate\Support\Facades\Schedule::command('files:prune --model=blocks')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Системная архивация устаревших прохождений — раз в сутки в 05:00.
// Критерии — в config/learn.php (переопределяются через .env).
\Illuminate\Support\Facades\Schedule::command('learn:archive')
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Сброс буфера статусов в БД — страховка раз в минуту
// (основной путь — батч по порогу; см. learn.status_logger).
\Illuminate\Support\Facades\Schedule::command('learn:flush-statuses')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
