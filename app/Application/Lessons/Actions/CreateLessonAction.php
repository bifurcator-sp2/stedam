<?php

declare(strict_types=1);

namespace App\Application\Lessons\Actions;

use App\Application\Lessons\DTOs\CreateLessonData;
use App\Application\Lessons\Ports\LessonRepositoryInterface;
use App\Domain\Lessons\Entities\Lesson;
use App\Domain\Lessons\ValueObjects\LessonType;
use App\Domain\Lessons\ValueObjects\UserId;

/**
 * Создать пустой урок-черновик.
 *
 * Инвариант: locale пустая → caller выбирает локаль (владелец/app.locale).
 * Application этот выбор не делает — он не знает, откуда пришло
 * значение. Поэтому локаль обязательна: null означает «не задана»
 * и подставляется как app.locale.
 *
 * Тип урока: null → тип по умолчанию (class_work). Явно переданный
 * код валидирует VO `LessonType` (форма кода), существование кода
 * в справочнике проверяет HTTP-слой.
 */
final readonly class CreateLessonAction
{
    public function __construct(
        private LessonRepositoryInterface $lessons,
    ) {}

    public function __invoke(UserId $userId, CreateLessonData $data): Lesson
    {
        $locale = $data->locale ?? (string) config('app.locale');
        $type = $data->type !== null ? new LessonType($data->type) : null;

        $lesson = Lesson::draft($userId, $locale, $type);

        return $this->lessons->save($lesson);
    }
}
