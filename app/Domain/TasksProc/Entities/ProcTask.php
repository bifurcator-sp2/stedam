<?php

declare(strict_types=1);

namespace App\Domain\TasksProc\Entities;

use App\Domain\Lessons\ValueObjects\BlockId;
use App\Domain\Lessons\ValueObjects\Complexity;
use App\Domain\Lessons\ValueObjects\UserId;
use App\Domain\TasksProc\Exceptions\ProcTaskAccessDenied;
use App\Domain\TasksProc\ValueObjects\ProcTaskId;

/**
 * Процедурное задание.
 *
 * Физически: lesson_blocks (lesson_id IS NULL, is_proc = true).
 *
 * Инварианты TasksProc:
 *   1. НЕТ привязки к таксономии — это принципиально. Процедурное
 *      задание «универсально»: генератор подбирает параметры по
 *      test_resolver раздела урока, а не по таксономии задания.
 *   2. complexity управляется вручную: именно по ней генератор
 *      выбирает сложность задачи.
 *   3. Создавать/менять может только admin. Это правило из
 *      существующей логики (proc-задания — системный контент).
 *
 * Всё, что в Tasks — «привязка к таксономии», здесь отсутствует
 * на уровне типа. Это и есть причина, по которой домены разные.
 */
final class ProcTask
{
    private function __construct(
        private ?ProcTaskId $id,
        private UserId $userId,
        private BlockId $blockId,
        private array $settings,
        private ?Complexity $complexity,
    ) {
    }

    // === Фабрики ===

    public static function create(
        UserId $userId,
        BlockId $blockId,
        array $settings,
        ?Complexity $complexity,
        bool $isAdmin,
    ): self {
        if (!$isAdmin) {
            throw ProcTaskAccessDenied::mutation($userId->value);
        }

        return new self(
            id: null,
            userId: $userId,
            blockId: $blockId,
            settings: $settings,
            complexity: $complexity,
        );
    }

    public static function fromPersistence(
        ProcTaskId $id,
        UserId $userId,
        BlockId $blockId,
        array $settings,
        ?Complexity $complexity,
    ): self {
        return new self(
            id: $id,
            userId: $userId,
            blockId: $blockId,
            settings: $settings,
            complexity: $complexity,
        );
    }

    // === Идентичность ===

    public function withId(ProcTaskId $id): self
    {
        if ($this->id !== null) {
            throw new \LogicException('ProcTask already has an id');
        }

        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    // === Геттеры ===

    public function id(): ?ProcTaskId
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

    // === Поведение ===

    /**
     * Заменить settings без пересчёта complexity.
     *
     * Используется инфраструктурой после прогона file-sets: пути к файлам
     * дописываются в settings, бизнес-поля (complexity) не меняются.
     *
     * @param array<string, mixed> $settings
     */
    public function replaceSettings(array $settings): void
    {
        $this->settings = $settings;
    }

    public function assertCanBeModifiedBy(UserId $userId, bool $isAdmin): void
    {
        if (!$isAdmin) {
            throw ProcTaskAccessDenied::mutation($userId->value);
        }
    }

    /**
     * @param bool $complexityTouched true — поле присутствовало в запросе
     *
     * @return bool true, если complexity изменилась (для пересчёта уроков)
     */
    public function update(
        array $settings,
        ?Complexity $complexity,
        bool $complexityTouched,
        bool $isAdmin,
        UserId $actor,
    ): bool {
        $this->assertCanBeModifiedBy($actor, $isAdmin);

        $complexityChanged = false;
        if ($complexityTouched && !$this->sameComplexity($complexity)) {
            $complexityChanged = true;
            $this->complexity = $complexity;
        }

        $this->settings = $settings;

        return $complexityChanged;
    }

    private function sameComplexity(?Complexity $other): bool
    {
        if ($this->complexity === null || $other === null) {
            return $this->complexity === null && $other === null;
        }

        return $this->complexity->equals($other);
    }
}
