<?php

declare(strict_types=1);

namespace App\Application\Learn\Ports;

use App\Domain\Learn\ValueObjects\LessonId;

/**
 * Поиск resolver-пути генератора для процедурных заданий.
 *
 * Алгоритм: у узла берём непустой test_resolver (точный матч по
 * зарегистрированным генераторам); иначе поднимаемся по parent_id
 * до ближайшего непустого и валидного. Так тема наследуется от родителя.
 *
 * Порт общий для снапшота и для генерации — чтобы алгоритм не дублировался
 * в разных местах (SettingsNormalizer, контроллеры, ассемблер/билдер).
 */
interface ProcedureResolverPathInterface
{
    /**
     * Ближайший валидный test_resolver для урока (по его таксономиям).
     * null — урок не привязан ни к одному узлу с генератором.
     */
    public function resolveForLesson(LessonId $lessonId): ?string;

    /**
     * Ближайший валидный test_resolver для узла таксономии и его предков.
     * null — не найден.
     */
    public function resolveForTaxonomy(int $taxonomyId): ?string;
}
