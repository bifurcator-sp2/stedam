<?php

declare(strict_types=1);

namespace App\Domain\Lessons\Entities;

use App\Domain\LessonBlocks\Exceptions\LessonIsPublished;
use App\Domain\Lessons\Exceptions\LessonAccessDenied;
use App\Domain\Lessons\Exceptions\LessonIsPublishedEdit;
use App\Domain\Lessons\ValueObjects\Complexity;
use App\Domain\Lessons\ValueObjects\LessonId;
use App\Domain\Lessons\ValueObjects\LessonType;
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
 *   1. Опубликованный урок нельзя редактировать: единственное допустимое
 *      изменение — снятие публикации (`published = false`), атомарно.
 *   2. complexity — производная от complexity заданий, не задаётся вручную.
 *   3. Владелец — единственный, кто может менять (кроме admin-override,
 *      который проверяется в Application).
 *   4. Тип урока (`type` — код справочника `lesson_types`) обязателен и
 *      меняется только пока урок не опубликован.
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
        private LessonType $type,
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
     * Название пустое, saved/published = false. Тип урока — обязателен:
     * по умолчанию классная работа, при создании можно передать другой.
     * complexity будет пересчитана при сохранении, как только в урок
     * добавят задания.
     */
    public static function draft(
        UserId $userId,
        string $locale,
        ?LessonType $type = null,
    ): self {
        return new self(
            id: null,
            userId: $userId,
            title: '',
            description: null,
            locale: $locale,
            type: $type ?? LessonType::default(),
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
        LessonType $type,
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
            type: $type,
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

    public function type(): LessonType
    {
        return $this->type;
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
     * Опубликованный урок (published === true) можно изменить только одним
     * способом — снять с публикации (`published = false`): прочие поля такого
     * запроса игнорируются. Любое другое изменение → LessonIsPublishedEdit.
     * Для неопубликованного урока ограничений нет (кроме инварианта 4 про type).
     *
     * @param list<TaxonomyId>|null $taxonomyIds
     */
    public function update(
        ?string $title,
        ?string $description,
        bool $descriptionTouched,
        ?string $locale,
        ?LessonType $type,
        ?bool $saved,
        ?bool $published,
        ?array $taxonomyIds,
    ): void {
        // Снятие публикации — атомарное действие: опубликованный урок сначала
        // снимают с публикации, и только следующим запросом меняют содержимое.
        // Остальные поля такого запроса игнорируются (фронт вправе отправить
        // весь объект урока целиком).
        if ($this->published && $published === false) {
            $this->published = false;

            return;
        }

        if ($this->published) {
            throw LessonIsPublishedEdit::forLesson(
                (int) ($this->id?->value ?? 0)
            );
        }

        if ($title !== null) {
            $this->title = $title;
        }
        if ($descriptionTouched) {
            $this->description = $description;
        }
        if ($locale !== null) {
            $this->locale = $locale;
        }
        if ($type !== null) {
            // Инвариант 4: тип фиксируется публикацией.
            $this->assertTypeChangeAllowed();
            $this->type = $type;
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

    /**
     * Тип урока можно менять только пока урок не опубликован.
     *
     * Опубликованность — строго `published === true`; снятие публикации
     * в том же запросе смену типа не открывает.
     */
    private function assertTypeChangeAllowed(): void
    {
        if ($this->published) {
            throw LessonIsPublishedEdit::forLesson(
                (int) ($this->id?->value ?? 0)
            );
        }
    }
}
