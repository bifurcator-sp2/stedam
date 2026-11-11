<?php

declare(strict_types=1);

namespace App\Domain\LessonBlocks\Exceptions;

use DomainException;

/**
 * Нарушение правил привязки задания к уроку.
 *
 * Примеры:
 *   — у блока нет id в syncBlocks;
 *   — блок с id не принадлежит уроку и не является глобальным заданием;
 *   — задание не соответствует таксономии урока;
 *   — урок содержит proc-задание, но у раздела нет генератора.
 */
final class BlockAssignmentDenied extends DomainException
{
    public static function missingId(): self
    {
        return new self('У блока отсутствует id.');
    }

    public static function unknownBlock(int $id, int $lessonId): self
    {
        return new self(
            "Блок с id={$id} не принадлежит уроку {$lessonId} и не является заданием."
        );
    }

    public static function taxonomyMismatch(int $blockId): self
    {
        return new self("Задание (ID: {$blockId}) не соответствует таксономии урока.");
    }

    public static function taskWithoutTaxonomy(int $blockId): self
    {
        return new self("Задание (ID: {$blockId}) не привязано ни к одному разделу таксономии.");
    }

    public static function procTaskWithoutResolver(int $blockId): self
    {
        return new self(
            "Урок содержит процедурное задание (ID: {$blockId}), "
            . 'но урок относится к разделу знаний, который не имеет генератора заданий.'
        );
    }
}
