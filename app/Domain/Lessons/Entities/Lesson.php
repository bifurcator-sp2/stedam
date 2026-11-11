<?php

declare(strict_types=1);

namespace App\Domain\Lessons\Entities;

use App\Domain\LessonBlocks\Exceptions\LessonIsPublished;
use App\Domain\Lessons\Exceptions\LessonAccessDenied;
use App\Domain\Lessons\ValueObjects\Complexity;
use App\Domain\Lessons\ValueObjects\LessonId;
use App\Domain\Lessons\ValueObjects\TaxonomyId;
use App\Domain\Lessons\ValueObjects\UserId;

/**
 * Урок.
 *
 * Состав урока физически разделён на две группы:
 *   — собственные блоки (lesson_blocks.lesson_id = this.id);
 *   — привязанные задания (pivot lesson_lesson_block_as_test), каждое
 *     из которых — глобальный LessonBlock, переиспользуемый в нескольких
 *     уроках.
 *
 * Агрегат хранит только СКАЛЯРНОЕ состояние урока и набор таксономий:
 * блоки — отдельный агрегат LessonBlock, они живут в своём репозитории.
 * Это осознанно: урок может содержать сотни блоков, и тянуть их при
 * каждой операции нельзя.
 *
 * Инварианты:
 *   1. Опубликованный урок нельзя редактировать (кроме снятия публикации).
 *   2. complexity — производная от complexity заданий, не задаётся вручную.
 *   3. Владелец — единственный, кто может менять (кроме admin-override,
 *      который проверяется в Application).
 */
final class Lesson
{
    /**
     * @param list<TaxonomyId> $taxonomyIds
     */
    private function __construct(
        private ?LessonId $id,
        private UserId $userId,
        private string $title,
        private ?string $description,
        private string $locale,
        private bool $saved,
        private bool $published,
        private ?Complexity $complexity,
        private array $taxonomyIds,
    ) {
    }

    // === Фабрики ===

    /**
     * Пустой черновик урока: то, что создаётся по кнопке «Создать урок».
     *
     * Название пустое, saved/published = false. complexity будет
     * пересчитана при сохранении, как только в урок добавят задания.
     */
    public static function draft(UserId $userId, string $locale): self
    {
        return new self(
            id: null,
            userId: $userId,
            title: '',
            description: null,
            locale: $locale,
            saved: false,
            published: false,
            complexity: null,
            taxonomyIds: [],
        );
    }

    // === Реконструкция ===

    /**
     * Восстановление из хранилища.
     *
     * @param list<TaxonomyId> $taxonomyIds
     */
    public static function fromPersistence(
        LessonId $id,
        UserId $userId,
        string $title,
        ?string $description,
        string $locale,
        bool $saved,
        bool $published,
        ?Complexity $complexity,
        array $taxonomyIds,
    ): self {
        return new self(
            id: $id,
            userId: $userId,
            title: $title,
            description: $description,
            locale: $locale,
            saved: $saved,
            published: $published,
            complexity: $complexity,
            taxonomyIds: array_values($taxonomyIds),
        );
    }

    // === Идентичность ===

    public function withId(LessonId $id): self
    {
        if ($this->id !== null) {
            throw new \LogicException('Lesson already has an id');
        }

        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    // === Геттеры ===

    public function id(): ?LessonId
    {
        return $this->id;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function isSaved(): bool
    {
        return $this->saved;
    }

    public function isPublished(): bool
    {
        return $this->published;
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
     * Описание изменений урока. Все поля опциональны:
     * null в DTO-слое — «не менять», отдельное правило для description.
     *
     * Защита от редактирования опубликованного урока — на уровне
     * Application (UpdateLessonAction): она решает, вызывать ли update()
     * вообще. Домен update() применяет изменения без дополнительных
     * проверок публикации, чтобы не дублировать источник истины.
     *
     * @param list<TaxonomyId>|null $taxonomyIds
     */
    public function update(
        ?string $title,
        ?string $description,
        bool $descriptionTouched,
        ?string $locale,
        ?bool $saved,
        ?bool $published,
        ?array $taxonomyIds,
    ): void {
        if ($title !== null) {
            $this->title = $title;
        }
        if ($descriptionTouched) {
            $this->description = $description;
        }
        if ($locale !== null) {
            $this->locale = $locale;
        }
        if ($saved !== null) {
            $this->saved = $saved;
        }
        if ($published !== null) {
            $this->published = $published;
        }
        if ($taxonomyIds !== null) {
            $this->taxonomyIds = array_values($taxonomyIds);
        }
    }

    /**
     * Только смена публикации: снять/поставить.
     */
    public function setPublished(bool $published): void
    {
        $this->published = $published;
    }

    /**
     * Пересчитать complexity по значениям всех привязанных заданий.
     *
     * @param list<float|null> $taskComplexities
     */
    public function recalculateComplexity(array $taskComplexities): void
    {
        $this->complexity = Complexity::averageOf($taskComplexities);
    }

    /**
     * Проверить, имеет ли право пользователь менять урок.
     *
     * @param bool $isAdmin admin-override
     */
    public function assertCanBeEditedBy(UserId $userId, bool $isAdmin = false): void
    {
        if ($isAdmin) {
            return;
        }

        if (!$this->userId->equals($userId)) {
            throw LessonAccessDenied::notOwner(
                (int) ($this->id?->value ?? 0),
                $userId->value,
            );
        }
    }

    /**
     * Публикация фиксирует содержимое: собственные блоки нельзя
     * добавлять/перемещать.
     */
    public function assertNotPublishedForStructureChange(): void
    {
        if ($this->published) {
            throw LessonIsPublished::forLesson(
                (int) ($this->id?->value ?? 0)
            );
        }
    }
}
