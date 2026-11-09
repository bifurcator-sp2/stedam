<?php

declare(strict_types=1);

namespace App\Application\Learn\Ports;

use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\UserId;

interface LearnRepositoryInterface
{
    /**
     * Сохраняет Learn. Если id === null — INSERT, возвращает копию с присвоенным id.
     * Если id задан — UPDATE.
     */
    public function save(Learn $learn): Learn;

    /**
     * @throws \App\Application\Learn\Exceptions\LearnNotFound
     */
    public function getById(LearnId $id): Learn;

    /**
     * Активное прохождение (не терминальное) по паре user+lesson.
     * Нужно для идемпотентности StartLearnAction.
     */
    public function findActiveByUserAndLesson(UserId $userId, LessonId $lessonId): ?Learn;
}
