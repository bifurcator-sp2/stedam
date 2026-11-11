<?php

declare(strict_types=1);

namespace App\Domain\Lessons\Services;

use App\Domain\LessonBlocks\Exceptions\BlockAssignmentDenied;
use App\Domain\Lessons\ValueObjects\TaxonomyId;

/**
 * Семантические правила урока перед сохранением.
 *
 * Проверяет только ТО, что видно домену: списки id таксономий и
 * описания привязанных заданий. Никаких запросов в БД здесь нет —
 * вызывающая сторона (Application/Infrastructure) подаёт уже готовые
 * данные.
 *
 * Правила (перенесены из LessonController::validateLessonRules как есть):
 *
 *   1. У задания должна быть привязка к таксономии: хотя бы один
 *      taxonomy_id задания должен входить в «расширенный» набор
 *      таксономий урока (сам урок + все потомки).
 *   2. Исключение — процедурные задания: им таксономия не нужна.
 *   3. Если в уроке есть proc-задание, у раздела знаний урока
 *      должен быть test_resolver (иначе генератор не сможет
 *      материализовать задачи).
 *
 * Важно: «роль в уроке» определяет правила, а НЕ Block::is_test.
 * Block::is_test — атрибут шаблона; шаблон с is_test = true может
 * использоваться как обычный info-блок. Поэтому проверяются только
 * привязанные через pivot задания (AttachedTask).
 */
final readonly class LessonRulesChecker
{
    /**
     * @param list<TaxonomyId> $lessonTaxonomyIds собственные таксономии урока
     * @param list<TaxonomyId> $expandedTaxonomyIds расширенный набор (урок + потомки),
     *                                              который подсчитан снаружи
     * @param list<AttachedTask> $attachedTasks    задания, привязанные к уроку
     * @param bool $lessonHasResolver true, если у одного из разделов урока
     *                                есть непустой test_resolver
     *
     * @throws BlockAssignmentDenied
     */
    public function check(
        array $lessonTaxonomyIds,
        array $expandedTaxonomyIds,
        array $attachedTasks,
        bool $lessonHasResolver,
    ): void {
        $expanded = array_map(static fn (TaxonomyId $t): int => $t->value, $expandedTaxonomyIds);
        $expandedSet = array_fill_keys($expanded, true);

        foreach ($attachedTasks as $task) {
            // 1) Соответствие таксономии — только если у урока есть таксономии.
            if ($lessonTaxonomyIds !== []) {
                $taskTaxIds = array_map(static fn (TaxonomyId $t): int => $t->value, $task->taxonomyIds);

                if ($taskTaxIds === []) {
                    // Исключение для proc-заданий: у них таксономии нет по определению.
                    if (!$task->isProc) {
                        throw BlockAssignmentDenied::taskWithoutTaxonomy($task->blockId);
                    }
                } else {
                    $matches = false;
                    foreach ($taskTaxIds as $id) {
                        if (isset($expandedSet[$id])) {
                            $matches = true;
                            break;
                        }
                    }
                    if (!$matches) {
                        throw BlockAssignmentDenied::taxonomyMismatch($task->blockId);
                    }
                }
            }

            // 2) Proc-задание требует resolver раздела.
            if ($task->isProc && !$lessonHasResolver) {
                throw BlockAssignmentDenied::procTaskWithoutResolver($task->blockId);
            }
        }
    }
}
