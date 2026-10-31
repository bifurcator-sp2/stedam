<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LearnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'user_id'    => $this->user_id,
            'lesson_id'  => $this->lesson_id,

            // Отдаем сохраненный JSONB (который уже является сериализованным LessonResource)
            'lesson'     => $this->lesson,

            'complete'   => $this->complete?->toISOString(),
            'score'      => (float) $this->score,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
