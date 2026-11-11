<?php

declare(strict_types=1);

namespace App\Application\LessonBlocks\Ports;

use App\Domain\LessonBlocks\Entities\LessonBlock;
use App\Domain\LessonBlocks\ValueObjects\LessonBlockId;
use App\Domain\Lessons\ValueObjects\LessonId;
use App\Domain\Lessons\ValueObjects\TaxonomyId;

/**
 * Репозиторий блоков урока и глобальных заданий (lesson_blocks).
 *
 * Один репозиторий для обеих ролей, потому что это одна сущность.
 * Права/роли различаются в Application — репозиторий про них не знает.
 */
interface LessonBlockRepositoryInterface
{
    /**
     * Сохраняет блок (INSERT или UPDATE).
     * Для INSERT возвращает копию с id.
     */
    public function save(LessonBlock $block): LessonBlock;

    public function getById(LessonBlockId $id): LessonBlock;

    public function findById(LessonBlockId $id): ?LessonBlock;

    /**
     * Все блоки урока в порядке order — объединение собственных блоков
     * и привязанных заданий. Каждый элемент — LessonBlock; для привязанных
     * заданий order ставится из pivot (как pivot.order), чтобы эквивалентность
     * с текущим getLessonsListQuery/эндпоинтами сохранилась.
     *
     * @return list<LessonBlock>
     */
    public function findAllOrdered(LessonId $lessonId): array;

    /**
     * Собственные блоки урока (lesson_id = this.id).
     *
     * @return list<LessonBlock>
     */
    public function findOwn(LessonId $lessonId): array;

    /**
     * id-шники заданий, привязанных к уроку через pivot, в порядке pivot.order.
     *
     * @return list<int>
     */
    public function pluckAttachedTaskIds(LessonId $lessonId): array;

    /**
     * Привязать задание к уроку на позицию order.
     * Если позиция уже занята — сдвигает всё, что >= order, на +1.
     */
    public function attachToLesson(LessonBlockId $taskId, LessonId $lessonId, int $order): void;

    /**
     * Заменить весь набор привязанных заданий у урока.
     * Реализация отвечает за сдвиг и целостность order.
     *
     * @param list<array{id: int, order: int}> $attachments
     */
    public function replaceAttachments(LessonId $lessonId, array $attachments): void;

    /**
     * Поставить собственный блок урока на позицию order,
     * сдвинув всё, что было на order и правее, на +1.
     *
     * Гарантирует уникальность order (использует двухфазный сдвиг).
     */
    public function placeOwnAt(LessonBlockId $blockId, LessonId $lessonId, int $order): LessonBlock;

    /**
     * Отвязать задание от урока (удалить строку pivot).
     */
    public function detachFromLesson(LessonBlockId $taskId, LessonId $lessonId): void;

    /**
     * Удалить блок физически или мягко — решает инфраструктура.
     * Гарантия: после delete findById() = null.
     */
    public function delete(LessonBlockId $id): void;

    /**
     * Удалить все собственные блоки урока, кроме указанных id.
     *
     * @param list<int> $keepIds
     */
    public function deleteOwnExcept(LessonId $lessonId, array $keepIds): void;

    /**
     * Максимальный order по собственным блокам урока и pivot. null, если блоков нет.
     */
    public function maxOrderInLesson(LessonId $lessonId): ?int;

    /**
     * Сериализованная схема настроек прототипа по его code.
     * Нужна Application, чтобы SettingsNormalizer мог прогонять settings
     * без прямого знания о BlockSettingsRegistry.
     *
     * @return array<string, mixed>
     */
    public function settingsSchemaForCode(string $blockCode): array;

    /**
     * Прогон settings через SettingsNormalizer::extractPublicDefaults():
     * оставляем только публичные узлы и подставляем schema-defaults.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function extractPublicDefaults(array $settings, array $schema): array;

    /**
     * Синхронизировать FileSet'ы модели с настройками.
     * Заменяет прямую работу с SettingsNormalizer::syncFileSetsFromSettings.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed> обновлённые settings (файловые пути обновлены)
     */
    public function syncFileSetsFromSettings(LessonBlockId $blockId, array $settings, string $blockCode): array;

    /**
     * Клонировать FileSet'ы прототипа Block в FileSet'ы этого блока
     * и вернуть обновлённые settings (ссылки проставлены).
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function cloneFileSetsFromPrototype(LessonBlockId $blockId, array $settings, int $prototypeBlockId, string $blockCode): array;

    /**
     * Синхронизация таксономий задания.
     *
     * @param list<TaxonomyId> $taxonomyIds
     */
    public function syncTaskTaxonomies(LessonBlockId $taskId, array $taxonomyIds): void;

    /**
     * Таксономии задания.
     *
     * @return list<TaxonomyId>
     */
    public function taskTaxonomies(LessonBlockId $taskId): array;

    /**
     * Прототип Block: id, code, settings, is_test, is_proc.
     *
     * Возвращает null, если прототип не найден.
     *
     * @return array{id:int, code:string, settings:array, is_test:bool, is_proc:bool}|null
     */
    public function findBlockPrototype(int $blockId): ?array;

    /**
     * Сложности всех заданий, привязанных к уроку — для пересчёта complexity.
     *
     * @return list<float|null>
     */
    public function pluckAttachedComplexities(LessonId $lessonId): array;

    /**
     * Используется ли задание хоть в одном уроке?
     */
    public function isTaskUsedInLessons(LessonBlockId $taskId): bool;
}
