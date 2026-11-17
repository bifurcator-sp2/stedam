<?php

declare(strict_types=1);

namespace App\Application\Lessons\Ports;

use App\Application\Lessons\DTOs\LessonTypeOption;

/**
 * Read-side справочника типов урока (`lesson_types`).
 *
 * Отделён от LessonsQueryInterface: это не чтение уроков, а справочные
 * данные для выбора `type` в интерфейсе. Возвращает готовые к сериализации
 * элементы — HTTP-слой не обращается к моделям.
 */
interface LessonTypeQueryInterface
{
    /**
     * Все типы в порядке отображения (`sort`, затем id), с локализованными
     * `title`/`description` на текущую локаль.
     *
     * @return list<LessonTypeOption>
     */
    public function allOrdered(): array;

    /**
     * Один тип по машинному коду (`code`) — или null, если такого нет.
     *
     * Нужен read-моделям, которые хранят только код типа (например,
     * `lessons.type`) и должны отдать его локализованное название.
     */
    public function optionByCode(string $code): ?LessonTypeOption;
}
