<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LearnGradeStatus;
use App\Infrastructure\Grade\MLpregrader;
use App\Models\LearnGrade;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Долгоживущий поштучный воркер предварительной оценки.
 *
 * Берёт самый старый pending LearnGrade, атомарно клеймит его короткой
 * транзакцией (pre_grading + аренда: pre_grade_worker / pre_grade_lease_until)
 * и передаёт в MLpregrader, который рендерит вайтборды и ставит
 * pre_graded / failed.
 *
 * Безопасен для запуска в нескольких копиях: конкуренция снимается
 * SELECT ... FOR UPDATE SKIP LOCKED, а владение строкой подтверждается
 * через pre_grade_worker — и здесь, и в MLpregrader на финальной записи.
 * Долгих транзакций на время работы (рендер + внешний API) нет.
 *
 * Recovery: при старте возвращаются в pending только просроченные аренды,
 * т.е. pre_grading, которые уже не держит живой воркер.
 */
class PreGradeLearnsCommand extends Command
{
    protected $signature = 'learn:pre-grade
                            {--sleep=2 : Пауза в секундах при пустой очереди}
                            {--lease=180 : Длительность аренды в секундах}';

    protected $description = 'Рендерит вайтборды pending-оценок (поштучно, синхронный цикл)';

    /** Идентификатор этого процесса среди копий воркера. */
    private string $workerId;

    private int $leaseSeconds;

    public function handle(MLpregrader $pregrader): int
    {
        $sleep  = max(1, (int) $this->option('sleep'));

        $this->leaseSeconds = max(30, (int) $this->option('lease'));
        $this->workerId     = Str::uuid()->toString();

        $this->recoverExpiredLeases();

        $this->info("Pre-grader started (worker {$this->workerId}).");

        while (true) {
            $grade = $this->claimNext();

            if ($grade === null) {
                sleep($sleep);
                $this->recoverExpiredLeases();
                continue;
            }

            $this->info("Claimed LearnGrade #{$grade->id}");

            try {
                $pregrader->pregrade($grade, $this->workerId, $this->leaseSeconds);

                $this->info("LearnGrade #{$grade->id} → pre_graded");
            } catch (Throwable $e) {
                $this->error("LearnGrade #{$grade->id} → failed: {$e->getMessage()}");
            }

            // Строка после обработки уже не наша — отпускаем аренду.
            unset($grade);
        }
    }

    /**
     * Возвращает в pending только строки с просроченной арендой.
     * Живые pre_grading других воркеров не трогает.
     */
    private function recoverExpiredLeases(): void
    {
        $recovered = LearnGrade::query()
            ->where('status', LearnGradeStatus::PreGrading->value)
            ->where(function ($query): void {
                $query->whereNull('pre_grade_lease_until')
                    ->orWhere('pre_grade_lease_until', '<', now());
            })
            ->update([
                'status'                => LearnGradeStatus::Pending->value,
                'pre_grade_worker'      => null,
                'pre_grade_lease_until' => null,
            ]);

        if ($recovered > 0) {
            $this->warn("Recovered expired pre_grading: {$recovered}");
        }
    }

    /**
     * Атомарный claim самого старого pending (skipLocked — соседние не ждут).
     * Транзакция короткая: работа выполняется уже после коммита.
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

            $grade->status                = LearnGradeStatus::PreGrading->value;
            $grade->pre_grade_worker      = $this->workerId;
            $grade->pre_grade_lease_until = now()->addSeconds($this->leaseSeconds);
            $grade->save();

            return $grade;
        });
    }
}
