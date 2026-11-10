<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\Learn\Ports\AnswerStorageInterface;
use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\ValueObjects\AnswerImage;
use App\Domain\Learn\ValueObjects\LearnAnswers;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Прохождение (learns) в новой схеме жизненного цикла.
 *
 * Схема:
 *   status          — created|scheduled|in_progress|done|graded
 *   status_date     — момент последней смены статуса
 *   schedule_date   — плановое время (для scheduled)
 *   lesson_snapshot — хэш снапшота урока (для in_progress+)
 *   score           — оценка (для graded)
 *   graded_by       — кто оценил (null — автоматически)
 *   answers         — ответы ученика (JSON)
 *   complete_date   — момент перехода в done
 *   archived_at     — архив (системный)
 */
class LearnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status;

        return [
            'id'              => $this->id,
            'user_id'         => $this->user_id,
            'lesson_id'       => $this->lesson_id,
            'status'          => $status instanceof LearnStatus ? $status->value : (string) $status,
            'status_date'     => $this->status_date?->toIso8601String(),
            'schedule_date'   => $this->schedule_date?->toIso8601String(),
            'lesson_snapshot' => $this->lesson_snapshot,
            'score'           => $this->score !== null ? (float) $this->score : null,
            'graded_by'       => $this->graded_by,
            'answers'         => $this->answersPayload($this->answers ?? []),
            'complete_date'   => $this->complete_date?->toIso8601String(),
            'archived_at'     => $this->archived_at?->toIso8601String(),
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Ответы-изображения как [{index, url}].
     *
     * @param array<int, array{index?: mixed, file?: mixed}>|null $raw
     * @return list<array{index: int, url: string}>
     */
    private function answersPayload(?array $raw): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }

        /** @var AnswerStorageInterface $storage */
        $storage = app(AnswerStorageInterface::class);

        return array_map(
            static fn (AnswerImage $img): array => [
                'index' => $img->index,
                'url'   => $storage->urlFor($img),
            ],
            LearnAnswers::fromArray($raw)->images(),
        );
    }
}
