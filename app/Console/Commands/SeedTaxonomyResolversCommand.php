<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Blocks\TestGenerator\Ports\GeneratorCatalogInterface;
use App\Models\Taxonomy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Проставляет test_resolver листьям таксономии.
 *
 * Назначение — удобство разработки: чтобы каждый лист дерева имел
 * реально существующий генератор, и SeedLotrLessonsCommand /
 * TaskForLearnController не спотыкались о пустой test_resolver.
 *
 * Релевантность (соответствие темы листа и предмета генератора)
 * НЕ проверяется: берётся любой путь из каталога.
 *
 * Источник правды — доменный порт GeneratorCatalogInterface
 * (реализация сканирует Generators/), поэтому список путей
 * всегда совпадает с тем, что реально доступно в приложении.
 *
 * Лист таксономии — узел без живых (deleted_at IS NULL) детей.
 *
 * Запуск:
 *   ./vendor/bin/sail artisan taxonomy:seed-resolvers --dry-run
 *   ./vendor/bin/sail artisan taxonomy:seed-resolvers
 *   ./vendor/bin/sail artisan taxonomy:seed-resolvers --overwrite --force
 */
final class SeedTaxonomyResolversCommand extends Command
{
    protected $signature = 'taxonomy:seed-resolvers
        {--overwrite : Перезаписывать уже заданные test_resolver}
        {--dry-run : Показать план, ничего не записывая}
        {--seed= : Фиксированный сид для воспроизводимого распределения}
        {--force : Не спрашивать подтверждения}';

    protected $description = 'Проставляет случайные реально существующие test_resolver листьям таксономии';

    public function handle(GeneratorCatalogInterface $catalog): int
    {
        $paths = $catalog->availablePaths();

        if ($paths === []) {
            $this->error('Каталог генераторов пуст: нет ни одного реализованного resolver-пути.');
            return self::FAILURE;
        }

        $this->info(sprintf('Доступно генераторов: %d', count($paths)));

        $leaves = $this->leafTaxonomies();

        if ($leaves === []) {
            $this->warn('Листьев таксономии не найдено.');
            return self::SUCCESS;
        }

        $overwrite = (bool) $this->option('overwrite');

        $toUpdate = $overwrite
            ? $leaves
            : array_values(array_filter(
                $leaves,
                static fn (array $leaf): bool => $leaf['test_resolver'] === null
                    || trim((string) $leaf['test_resolver']) === '',
            ));

        $skipped = count($leaves) - count($toUpdate);

        $this->info(sprintf(
            'Листьев: %d, к заполнению: %d, пропущено (уже задан): %d',
            count($leaves),
            count($toUpdate),
            $skipped,
        ));

        if ($toUpdate === []) {
            $this->line('Нечего обновлять.');
            return self::SUCCESS;
        }

        if ($this->option('seed') !== null && $this->option('seed') !== '') {
            mt_srand((int) $this->option('seed'));
        }

        // Распределение: каждому листу — случайный путь из каталога.
        $plan = [];
        foreach ($toUpdate as $leaf) {
            $plan[] = [
                'id'    => $leaf['id'],
                'title' => $leaf['title'],
                'path'  => $paths[array_rand($paths)],
            ];
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->table(
            ['id', 'title', 'test_resolver'],
            array_map(
                static fn (array $row): array => [$row['id'], $row['title'], $row['path']],
                $dryRun ? $plan : array_slice($plan, 0, 10),
            ),
        );

        if ($dryRun) {
            $this->newLine();
            $this->warn('--dry-run: изменения НЕ применены.');
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm(
            sprintf('Записать test_resolver %d листьям?', count($plan)),
        )) {
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar(count($plan));
        $bar->start();

        $updated = 0;

        DB::transaction(function () use ($plan, $bar, &$updated): void {
            foreach ($plan as $row) {
                // update() не поднимает events/dirty-логику больше, чем нужно,
                // и не дергает translations — это ровно то, что надо.
                $affected = DB::table('taxonomies')
                    ->where('id', $row['id'])
                    ->update([
                        'test_resolver' => $row['path'],
                        'updated_at'    => now(),
                    ]);

                $updated += $affected;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf('Готово: обновлено %d листьев.', $updated));

        return self::SUCCESS;
    }

    /**
     * Листья таксономии (без живых детей).
     *
     * @return list<array{id:int, title:string, test_resolver:?string}>
     */
    private function leafTaxonomies(): array
    {
        $rows = DB::table('taxonomies as t')
            ->leftJoin('taxonomy_translations as tr', function ($join): void {
                $join->on('tr.taxonomy_id', '=', 't.id')
                    ->where('tr.locale', '=', 'ru');
            })
            ->whereNull('t.deleted_at')
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('taxonomies as c')
                    ->whereColumn('c.parent_id', 't.id')
                    ->whereNull('c.deleted_at');
            })
            ->orderBy('t.id')
            ->get(['t.id', 't.test_resolver', 'tr.title']);

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'id'            => (int) $row->id,
                'title'         => (string) ($row->title ?? ''),
                'test_resolver' => $row->test_resolver !== null ? (string) $row->test_resolver : null,
            ];
        }

        return $out;
    }
}
