<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Learn\Enums\LearnStatus;
use App\Models\Learn;
use App\Models\LearnGrade;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Создаёт недостающие оценки (learn_grades) для завершённых прохождений.
 *
 * Отбирает learns со статусом done, у которых ещё нет ни одной связанной
 * LearnGrade, и создаёт для каждого ровно одну пустую оценку.
 * Обработка — чанками по 1000 строк.
 */
class MakeLearnGradesCommand extends Command
{
    protected $signature = 'learn:make-grades
                            {--chunk=1000 : Размер чанка}
                            {--dry-run : Показать количество кандидатов, ничего не создавая}';

    protected $description = 'Создаёт learn_grades для прохождений в статусе done, у которых их ещё нет';

    public function handle(): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $query = Learn::query()
            ->where('status', LearnStatus::Done->value)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('learn_grades')
                    ->whereColumn('learn_grades.learn_id', 'learns.id');
            })
            ->orderBy('learns.id');

        $total = (clone $query)->count();

        if ($dryRun) {
            $this->info("Кандидатов на создание оценок: {$total}");
            return self::SUCCESS;
        }

        if ($total === 0) {
            $this->info('Нет прохождений без оценки.');
            return self::SUCCESS;
        }

        $this->info("К обработке: {$total}");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $created = 0;

        // chunkById по learns.id: whereNotExists пересчитывается на каждом чанке,
        // поэтому уже созданные оценки автоматически исключаются.
        $query->chunkById($chunkSize, function ($learns) use (&$created, $bar) {
            $now = now();

            $rows = $learns->map(static fn (Learn $learn): array => [
                'learn_id'       => $learn->id,
                'grader_comment' => '',
                'created_at'     => $now,
                'updated_at'     => $now,
            ])->all();

            if ($rows !== []) {
                $created += DB::table('learn_grades')->insert($rows);
            }

            $bar->advance($learns->count());
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Создано оценок: {$created}");

        return self::SUCCESS;
    }
}
