<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Learn\Actions\ArchiveOldLearnsAction;
use App\Application\Learn\DTOs\ArchiveCriteria;
use App\Domain\Learn\Enums\LearnStatus;
use Illuminate\Console\Command;

/**
 * Системная архивация устаревших прохождений.
 *
 * Критерии по умолчанию — из config('learn.archive.after_days')
 * (переопределяются через .env). Команда позволяет сделать разовый
 * сдвиг порогов/статусов без правки конфига.
 */
class ArchiveLearns extends Command
{
    protected $signature = 'learn:archive
                            {--dry-run : Показать кандидатов, ничего не архивируя}
                            {--days=  : Разовый override порога дней для всех статусов из критериев}
                            {--status=* : Разовый override набора статусов (graded, created, scheduled)}';

    protected $description = 'Отправляет устаревшие прохождения в архив (по критериям из config/learn.php)';

    public function handle(ArchiveOldLearnsAction $action): int
    {
        if (!config('learn.archive.enabled', true)) {
            $this->warn('Архивация отключена (learn.archive.enabled = false).');
            return self::SUCCESS;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone(config('app.timezone', 'UTC')));

        $criteria = ArchiveCriteria::fromConfig(
            (array) config('learn.archive', []),
            $now,
        );

        // ---- CLI overrides ----
        $days = $this->option('days');
        $days = $days === null ? null : (int) $days;

        $statuses = [];
        foreach ((array) $this->option('status') as $raw) {
            $status = LearnStatus::tryFrom((string) $raw);
            if ($status === null) {
                $this->error("Неизвестный статус: {$raw}");
                return self::FAILURE;
            }
            $statuses[] = $status;
        }

        $criteria = $criteria->withOverrides($statuses, $days);

        if ($criteria->isEmpty()) {
            $this->warn('Нет критериев архивации (пустой набор статусов).');
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        // ---- Отчёт о критериях ----
        $this->info($dryRun ? '=== DRY-RUN (изменения не сохраняются) ===' : '=== Архивация ===');
        foreach ($criteria->daysByStatus as $status => $d) {
            $this->line(sprintf('  %-10s → старше %d дн.', $status, $d));
        }

        $result = $action($criteria, $dryRun);

        $this->newLine();
        foreach ($result->archivedByStatus as $status => $count) {
            $this->line(sprintf('  %-10s : %d', $status, $count));
        }

        $this->info(sprintf(
            '%s %d прохождений.',
            $dryRun ? 'Кандидатов в архив:' : 'Отправлено в архив:',
            $result->total(),
        ));

        return self::SUCCESS;
    }
}
