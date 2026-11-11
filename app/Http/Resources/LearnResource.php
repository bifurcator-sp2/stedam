<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\Learn\Ports\AnswerStorageInterface;
use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\ValueObjects\AnswerImage;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Прохождение (learns) в схеме жизненного цикла.
 *
 * Работает напрямую с доменной сущностью Learn (без повторной загрузки
 * Eloquent-модели по id).
 *
 * Схема:
 *   status          — created|scheduled|in_progress|done|graded
 *   status_date     — момент последней смены статуса
 *   schedule_date   — плановое время (для scheduled)
 *   lesson_snapshot — хэш снапшота урока (для in_progress+)
 *   score           — оценка (для graded)
 *   graded_by       — кто оценил (null — автоматически)
 *   questions       — сгенерированные задания прохождения (только proc-блоки)
 *   answers         — ответы ученика (изображения, только на задания)
 *   complete_date   — момент перехода в done
 *   archived_at     — архив (системный)
 *   updated_at      — приблизительный момент последнего изменения (= statusDate)
 */
class LearnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Learn $learn */
        $learn = $this->resource;

        return [
            'id'              => $learn->id()?->value,
            'user_id'         => $learn->userId()->value,
            'lesson_id'       => $learn->lessonId()->value,
            'status'          => $learn->status()->value,
            'status_date'     => $learn->statusDate()->format(DateTimeInterface::ATOM),
            'schedule_date'   => $learn->scheduleDate()?->value->format(DateTimeInterface::ATOM),
            'lesson_snapshot' => $learn->snapshot()?->value,
            'score'           => $learn->grade()?->value,
            'graded_by'       => $learn->gradedBy()?->value,
            'questions'       => $learn->questions()->toArray(),
            'answers'         => $this->answersPayload($learn),
            'complete_date'   => $learn->completedAt()?->format(DateTimeInterface::ATOM),
            'archived_at'     => $learn->archivedAt()?->format(DateTimeInterface::ATOM),
            'created_at'      => $learn->createdAt()->format(DateTimeInterface::ATOM),
            // У доменной сущности нет updated_at; отдаём statusDate
            // как лучшую оценку момента последнего изменения.
            'updated_at'      => $learn->statusDate()->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Ответы-изображения как [{index, url}].
     *
     * @return list<array{index: int, url: string}>
     */
    private function answersPayload(Learn $learn): array
    {
        $images = $learn->answers()->images();
        if ($images === []) {
            return [];
        }

        /** @var AnswerStorageInterface $storage */
        $storage = app(AnswerStorageInterface::class);

        return array_map(
            static fn (AnswerImage $img): array => [
                'index' => $img->index,
                'url'   => $storage->urlFor($img),
            ],
            $images,
        );
    }
}
