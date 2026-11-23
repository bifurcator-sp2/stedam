<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LearnGradeStatus;
use App\Infrastructure\Grade\MLpregrader;
use App\Models\LearnGrade;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Долгоживущий поштучный воркер предварительной оценки.
 *
 * Берёт самый старый pending LearnGrade, атомарно клеймит его
 * (pre_grading через lockForUpdate + skipLocked), передаёт в MLpregrader,
 * который рендерит вайтборды и ставит pre_graded / failed.
 *
 * Один процесс, без параллелизма и без очередей.
 * Проверка:
 * docker compose ps pre-grader
 */
class PreGradeLearnsCommand extends Command
{
    protected $signature = 'learn:pre-grade
                            {--sleep=2 : Пауза в секундах при пустой очереди}
                            {--width=854 : Ширина холста по умолчанию}
                            {--height=314 : Высота холста по умолчанию}';

    protected $description = 'Рендерит вайтборды pending-оценок (поштучно, синхронный цикл)';

    public function handle(MLpregrader $pregrader): int
    {
        $sleep  = max(1, (int) $this->option('sleep'));
        $width  = max(1, (int) $this->option('width'));
        $height = max(1, (int) $this->option('height'));

        // Recovery: залипшие pre_grading (рестарт контейнера) → pending.
        $recovered = LearnGrade::query()
            ->where('status', LearnGradeStatus::PreGrading->value)
            ->update(['status' => LearnGradeStatus::Pending->value]);

        if ($recovered > 0) {
            $this->warn("Recovered pre_grading: {$recovered}");
        }

        $this->info('Pre-grader started.');

        while (true) {
            $grade = $this->claimNext();

            if ($grade === null) {
                sleep($sleep);
                continue;
            }

            $this->info("Claimed LearnGrade #{$grade->id}");

            try {
                $pregrader->pregrade($grade, $width, $height);

                $this->info("LearnGrade #{$grade->id} → pre_graded");
            } catch (Throwable $e) {
                $this->error("LearnGrade #{$grade->id} → failed: {$e->getMessage()}");
            }
        }
    }

    /**
     * Атомарный claim самого старого pending (skipLocked — соседние не ждут).
     */
    private function claimNext(): ?LearnGrade
    {
        return DB::transaction(function (): ?LearnGrade {
            $grade = LearnGrade::query()
                ->where('status', LearnGradeStatus::Pending->value)
                ->orderBy('created_at')
                ->orderBy('id')
                // Явный SQL: Laravel 13 убрал skipLocked(), грамматика pgsql
                // принимает строку блокировки как есть.
                ->lock('for update skip locked')
                ->first();

            if ($grade === null) {
                return null;
            }

            $grade->status = LearnGradeStatus::PreGrading->value;
            $grade->save();

            return $grade;
        });
    }
}
