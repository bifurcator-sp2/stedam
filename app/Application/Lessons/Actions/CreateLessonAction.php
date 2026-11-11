<?php

declare(strict_types=1);

namespace App\Application\Lessons\Actions;

use App\Application\Lessons\DTOs\CreateLessonData;
use App\Application\Lessons\Ports\LessonRepositoryInterface;
use App\Domain\Lessons\Entities\Lesson;
use App\Domain\Lessons\ValueObjects\UserId;

/**
 * Создать пустой урок-черновик.
 *
 * Инвариант: locale пустая → caller выбирает локаль (владелец/app.locale).
 * Application этот выбор не делает — он не знает, откуда пришло
 * значение. Поэтому локаль обязательна: null означает «не задана»
 * и подставляется как app.locale.
 */
final readonly class CreateLessonAction
{
    public function __construct(
        private LessonRepositoryInterface $lessons,
    ) {}

    public function __invoke(UserId $userId, CreateLessonData $data): Lesson
    {
        $locale = $data->locale ?? (string) config('app.locale');

        $lesson = Lesson::draft($userId, $locale);

        return $this->lessons->save($lesson);
    }
}
