<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Entities;

use App\Domain\Lessons\ValueObjects\BlockId;
use App\Domain\Lessons\ValueObjects\Complexity;
use App\Domain\Lessons\ValueObjects\TaxonomyId;
use App\Domain\Lessons\ValueObjects\UserId;
use App\Domain\Tasks\Exceptions\TaskAccessDenied;
use App\Domain\Tasks\Exceptions\TaskInUse;
use App\Domain\Tasks\Exceptions\TaxonomyRequired;
use App\Domain\Tasks\ValueObjects\TaskId;

/**
 * Обычное задание.
 *
 * Физически: lesson_blocks (lesson_id IS NULL, is_proc = false) +
 * привязка к таксономии через lesson_block_taxonomy.
 *
 * Инварианты Tasks:
 *   1. Задание глобально: не принадлежит ни одному уроку.
 *   2. Задание привязано хотя бы к одной таксономии.
 *   3. Пользователь-владелец может менять; admin — override.
 *   4. Если задание уже используется в уроках, менять набор таксономий
 *      нельзя (иначе нарушится согласованность уроков).
 *
 * Сущность НЕ отвечает за физическое хранение settings и filesets —
 * это забота репозитория/инфраструктуры. Домену важно только, что
 * settings — непрозрачный массив, привязанный к прототипу Block.
 */
final class Task
{
    /**
     * @param list<TaxonomyId> $taxonomyIds
     */
    private function __construct(
        private ?TaskId $id,
        private UserId $userId,
        private BlockId $blockId,
        private array $settings,
        private ?Complexity $complexity,
        private array $taxonomyIds,
    ) {
    }

    // === Фабрики ===

    /**
     * Новое задание. Таксономий должно быть >= 1.
     *
     * @param list<TaxonomyId> $taxonomyIds
     */
    public static function create(
        UserId $userId,
        BlockId $blockId,
        array $settings,
        ?Complexity $complexity,
        array $taxonomyIds,
    ): self {
        if ($taxonomyIds === []) {
            throw TaxonomyRequired::forTask();
        }

        return new self(
            id: null,
            userId: $userId,
            blockId: $blockId,
            settings: $settings,
            complexity: $complexity,
            taxonomyIds: array_values($taxonomyIds),
        );
    }

    // === Реконструкция ===

    /**
     * @param list<TaxonomyId> $taxonomyIds
     */
    public static function fromPersistence(
        TaskId $id,
        UserId $userId,
        BlockId $blockId,
        array $settings,
        ?Complexity $complexity,
        array $taxonomyIds,
    ): self {
        return new self(
            id: $id,
            userId: $userId,
            blockId: $blockId,
            settings: $settings,
            complexity: $complexity,
            taxonomyIds: array_values($taxonomyIds),
        );
    }

    // === Идентичность ===

    public function withId(TaskId $id): self
    {
        if ($this->id !== null) {
            throw new \LogicException('Task already has an id');
        }

        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    // === Геттеры ===

    public function id(): ?TaskId
    {
        return $this->id;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function blockId(): BlockId
    {
        return $this->blockId;
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->settings;
    }

    public function complexity(): ?Complexity
    {
        return $this->complexity;
    }

    /** @return list<TaxonomyId> */
    public function taxonomyIds(): array
    {
        return $this->taxonomyIds;
    }

    // === Поведение ===

    /**
     * Заменить settings без пересчёта complexity/таксономий.
     *
     * Используется инфраструктурой после прогона file-sets:
     * физические пути к файлам дописываются в settings, а бизнес-поля
     * (complexity, taxonomyIds) не меняются.
     *
     * @param array<string, mixed> $settings
     */
    public function replaceSettings(array $settings): void
    {
        $this->settings = $settings;
    }

    /**
     * Проверить право изменять задание.
     */
    public function assertCanBeModifiedBy(UserId $userId, bool $isAdmin = false): void
    {
        if ($isAdmin) {
            return;
        }
        if (!$this->userId->equals($userId)) {
            throw TaskAccessDenied::notOwner(
                (int) ($this->id?->value ?? 0),
                $userId->value,
            );
        }
    }

    /**
     * Обновить задание.
     *
     * @param list<TaxonomyId>|null $taxonomyIds   null — «не менять»
     * @param bool $taxonomyTouched                true — поле присутствовало в запросе
     * @param bool $complexityTouched              true — поле присутствовало в запросе
     * @param bool $isUsedInLessons                задание уже привязано к урокам
     *
     * @return bool true, если complexity изменилась (нужен пересчёт уроков)
     */
    public function update(
        array $settings,
        ?Complexity $complexity,
        bool $complexityTouched,
        ?array $taxonomyIds,
        bool $taxonomyTouched,
        bool $isUsedInLessons,
    ): bool {
        $complexityChanged = $complexityTouched && !$this->sameComplexity($complexity);

        if ($taxonomyTouched && $taxonomyIds !== null) {
            if ($taxonomyIds === []) {
                throw TaxonomyRequired::forTask();
            }
            if ($isUsedInLessons && !$this->sameTaxonomySet($taxonomyIds)) {
                throw TaskInUse::taxonomyChangeDenied((int) ($this->id?->value ?? 0));
            }
            $this->taxonomyIds = array_values($taxonomyIds);
        }

        $this->settings = $settings;

        if ($complexityTouched) {
            $this->complexity = $complexity;
        }

        return $complexityChanged;
    }

    /**
     * @param list<TaxonomyId> $other
     */
    private function sameTaxonomySet(array $other): bool
    {
        $a = array_map(static fn (TaxonomyId $t): int => $t->value, $this->taxonomyIds);
        $b = array_map(static fn (TaxonomyId $t): int => $t->value, $other);
        sort($a);
        sort($b);

        return $a === $b;
    }

    private function sameComplexity(?Complexity $other): bool
    {
        if ($this->complexity === null || $other === null) {
            return $this->complexity === null && $other === null;
        }

        return $this->complexity->equals($other);
    }
}
