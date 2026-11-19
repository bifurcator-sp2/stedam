<?php

declare(strict_types=1);

namespace App\Application\Learn\DTOs;

use App\Domain\Learn\ValueObjects\LearnQuestions;
use App\Domain\Learn\ValueObjects\QuestionItem;
use App\Domain\Learn\ValueObjects\SnapshotHash;

/**
 * Снапшот прохождения, готовый к отдаче в HTTP.
 *
 * snapshotData — уже сохранённый JSON снапшота as-is
 * (без пересборки из текущей структуры урока).
 */
final readonly class LearnSnapshotData
{
    public function __construct(
        public int $learnId,
        public SnapshotHash $hash,
        public string $snapshotData,
        public ?string $description = null,
        public ?string $type = null,
        public ?float $complexity = null,
        public array $taxonomyIds = [],
        public array $lessonTypes = [],
    ) {}

    /**
     * Метаданные урока, которых нет в снапшоте (старые снапшоты):
     * контроллер добирает их из текущей модели урока как fallback.
     *
     * Уже присутствующие в снапшоте ключи не перетираются.
     *
     * @param list<int> $taxonomyIds
     */
    public function withMetadataFallback(?string $description, ?string $type, ?float $complexity, array $taxonomyIds): self
    {
        return new self(
            learnId: $this->learnId,
            hash: $this->hash,
            snapshotData: $this->snapshotData,
            description: $description,
            type: $type,
            complexity: $complexity,
            taxonomyIds: $taxonomyIds,
        );
    }

    /**
     * Справочник типов урока идёт в ответ на уровне snapshot_data.
     *
     * @param list<array<string, mixed>> $lessonTypes
     */
    public function withLessonTypes(array $lessonTypes): self
    {
        return new self(
            learnId: $this->learnId,
            hash: $this->hash,
            snapshotData: $this->snapshotData,
            description: $this->description,
            type: $this->type,
            complexity: $this->complexity,
            taxonomyIds: $this->taxonomyIds,
            lessonTypes: $lessonTypes,
        );
    }

    /**
     * snapshot_data для HTTP: JSON снапшота как есть + справочник lesson_types;
     * description/type/complexity/taxonomy_ids добираются только если их в снапшоте нет.
     *
     * @return array<string, mixed>
     */
    public function snapshotDataArray(): array
    {
        $decoded = json_decode($this->snapshotData, true);

        $snapshot = is_array($decoded) ? $decoded : [];
        $snapshot['description'] ??= $this->description;
        $snapshot['type'] ??= $this->type;
        $snapshot['complexity'] ??= $this->complexity;
        $snapshot['taxonomy_ids'] ??= $this->taxonomyIds;
        $snapshot['lesson_types'] = $this->lessonTypes;

        return $snapshot;
    }

    /**
     * Ключ присутствует в снапшоте (включая null: «урок без типа» — это значение).
     */
    public function hasSnapshotKey(string $key): bool
    {
        $decoded = json_decode($this->snapshotData, true);

        return is_array($decoded) && array_key_exists($key, $decoded);
    }

    /**
     * Обратная операция к EloquentLessonSnapshotAssembler::blankProcNodes():
     * раскладывает задания прохождения по proc-блокам снапшота.
     *
     * Снапшот хранит только структуру — у proc-узлов default обнулён, а текст
     * задания живёт отдельно в learns.questions. Здесь подставляем вопросы
     * обратно: QuestionItem::index = позиция блока в allBlocksOrdered() + 1,
     * т.е. block['order'] + 1 — ровно тот же ключ, что узел получил бы при
     * заполнении из генератора (SettingsNormalizer::fillProcNodes).
     */
    public function withQuestionsFrom(LearnQuestions $questions): self
    {
        /** @var array<string, mixed>|null $snapshot */
        $snapshot = json_decode($this->snapshotData, true);

        if (!is_array($snapshot) || !isset($snapshot['blocks']) || !is_array($snapshot['blocks'])) {
            return $this;
        }

        $byIndex = [];
        foreach ($questions->items() as $item) {
            $byIndex[$item->index] = $item;
        }

        foreach ($snapshot['blocks'] as $k => $block) {
            if (!is_array($block) || !isset($block['settings']) || !is_array($block['settings'])) {
                continue;
            }

            /** @var QuestionItem|null $item */
            $item = $byIndex[((int) ($block['order'] ?? -1)) + 1] ?? null;
            if ($item === null) {
                continue;
            }

            $snapshot['blocks'][$k]['settings'] = $this->fillProcNodes($block['settings'], $item);
        }

        return new self(
            learnId: $this->learnId,
            hash: $this->hash,
            snapshotData: json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            description: $this->description,
            type: $this->type,
            complexity: $this->complexity,
            taxonomyIds: $this->taxonomyIds,
            lessonTypes: $this->lessonTypes,
        );
    }

    /**
     * Рекурсивно подставляет текст задания в default proc-узлов (тех, у
     * которых среди детей есть ключ 'testLabel') — зеркало blankProcNodes().
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function fillProcNodes(array $node, QuestionItem $item): array
    {
        // Список узлов (массив без 'key').
        if (!isset($node['key'])) {
            foreach ($node as $k => $child) {
                if (is_array($child)) {
                    $node[$k] = $this->fillProcNodes($child, $item);
                }
            }

            return $node;
        }

        $isProc = false;
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child) && ($child['key'] ?? '') === 'testLabel') {
                $isProc = true;
                break;
            }
        }

        if ($isProc) {
            $node['default'] = $item->question;
        }

        foreach ($node['children'] ?? [] as $k => $child) {
            if (is_array($child)) {
                $node['children'][$k] = $this->fillProcNodes($child, $item);
            }
        }

        return $node;
    }
}
