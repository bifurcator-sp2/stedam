<?php

declare(strict_types=1);

namespace App\Domain\LessonBlocks\Entities;

use App\Domain\LessonBlocks\ValueObjects\LessonBlockId;
use App\Domain\Lessons\ValueObjects\BlockId;
use App\Domain\Lessons\ValueObjects\Complexity;
use App\Domain\Lessons\ValueObjects\LessonId;
use App\Domain\Lessons\ValueObjects\UserId;

/**
 * Блок урока (lesson_blocks).
 *
 * Одна сущность играет две роли:
 *
 *   OWN      — собственный блок урока. lesson_id заполнен, order — в нём
 *              и только в нём; settings задаются через редактор урока.
 *
 *   SHARED   — глобальное задание (lesson_id = NULL). Собственного order
 *              нет, а позиция в уроке хранится в pivot
 *              lesson_lesson_block_as_test.order. Используется во многих
 *              уроках, поэтому сложность (complexity) глобальна.
 *
 * Почему одна сущность: физически это одна таблица, и обе роли
 * разделяют настройки/файлы/привязку к прототипу Block. Разделение
 * на два агрегата потребовало бы JOIN-таблицы-«костыля» и синхронизации.
 *
 * Домен не знает про Eloquent: идентификатор — VO, состояние копируется
 * репозиторием. Мутации возвращают void, применяются в памяти;
 * сохранение — обязанность репозитория.
 */
final class LessonBlock
{
    private function __construct(
        private ?LessonBlockId $id,
        private UserId $userId,
        private BlockId $blockId,
        private LessonId $lessonId,
        private int $order,
        private array $settings,
        private ?Complexity $complexity,
        private bool $isProc,
    ) {
    }

    // === Фабрики ===

    /**
     * Собственный блок урока. lesson_id и order заданы.
     */
    public static function own(
        UserId $userId,
        BlockId $blockId,
        LessonId $lessonId,
        int $order,
        array $settings = [],
        ?LessonBlockId $id = null,
    ): self {
        if ($order < 0) {
            throw new \InvalidArgumentException("Block order must be >= 0, got {$order}");
        }

        return new self(
            id: $id,
            userId: $userId,
            blockId: $blockId,
            lessonId: $lessonId,
            order: $order,
            settings: $settings,
            complexity: null, // собственный блок урока не участвует в сложности
            isProc: false,
        );
    }

    /**
     * Глобальное задание (shared). order здесь не имеет смысла —
     * позиция задаётся в pivot при привязке к уроку.
     *
     * @param bool $isProc true — процедурное задание (домен TasksProc);
     *                     false — обычное (домен Tasks).
     */
    public static function shared(
        UserId $userId,
        BlockId $blockId,
        array $settings = [],
        ?Complexity $complexity = null,
        bool $isProc = false,
        ?LessonBlockId $id = null,
    ): self {
        return new self(
            id: $id,
            userId: $userId,
            blockId: $blockId,
            lessonId: LessonId::none(),
            order: 0,
            settings: $settings,
            complexity: $complexity,
            isProc: $isProc,
        );
    }

    // === Реконструкция ===

    /**
     * Восстановление из хранилища. Единственный «мутабельный» вход:
     * агрегат должен совпадать со строкой БД один-в-один.
     */
    public static function fromPersistence(
        LessonBlockId $id,
        UserId $userId,
        BlockId $blockId,
        ?LessonId $lessonId,
        int $order,
        array $settings,
        ?Complexity $complexity,
        bool $isProc,
    ): self {
        return new self(
            id: $id,
            userId: $userId,
            blockId: $blockId,
            lessonId: $lessonId ?? LessonId::none(),
            order: $order,
            settings: $settings,
            complexity: $complexity,
            isProc: $isProc,
        );
    }

    // === Идентичность ===

    public function withId(LessonBlockId $id): self
    {
        if ($this->id !== null) {
            throw new \LogicException('LessonBlock already has an id');
        }

        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    // === Геттеры ===

    public function id(): ?LessonBlockId
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

    public function lessonId(): ?LessonId
    {
        return $this->lessonId->isNone() ? null : $this->lessonId;
    }

    public function order(): int
    {
        return $this->order;
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

    public function isProc(): bool
    {
        return $this->isProc;
    }

    /**
     * Глобальное задание (принадлежит пулу заданий, а не одному уроку).
     */
    public function isGlobal(): bool
    {
        return $this->lessonId->isNone();
    }

    // === Поведение ===

    /**
     * Заменить настройки. Собственный order не трогаем.
     *
     * @param array<string, mixed> $settings
     */
    public function replaceSettings(array $settings): void
    {
        $this->settings = $settings;
    }

    /**
     * Переместить собственный блок урока на новую позицию.
     */
    public function reorder(int $order): void
    {
        if ($order < 0) {
            throw new \InvalidArgumentException("Block order must be >= 0, got {$order}");
        }
        if ($this->isGlobal()) {
            throw new \LogicException(
                'Cannot set "order" on a global LessonBlock — position lives in the pivot.'
            );
        }
        $this->order = $order;
    }

    /**
     * Сменить complexity (только для глобальных заданий).
     */
    public function changeComplexity(?Complexity $complexity): void
    {
        if (!$this->isGlobal()) {
            throw new \LogicException('Complexity is meaningful only for global LessonBlocks');
        }
        $this->complexity = $complexity;
    }

    /**
     * Переставить роль proc/non-proc. Разрешено только для глобальных
     * заданий: у собственного блока урока роли нет.
     */
    public function changeKind(bool $isProc): void
    {
        if (!$this->isGlobal()) {
            throw new \LogicException('Own lesson blocks have no proc/non-proc role');
        }
        $this->isProc = $isProc;
    }

    /**
     * Владелец ли пользователь этого блока? Для «admin-override» сверху
     * проверяется роль — домен ролей не знает.
     */
    public function isOwnedBy(UserId $userId): bool
    {
        return $this->userId->equals($userId);
    }
}
