<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Справочник типов урока для выбора в интерфейсе.
 *
 * Принимает готовый Application-DTO — HTTP-слой не знает о модели
 * `LessonType`. `title`/`description` уже локализованы на момент сборки.
 *
 * @property \App\Application\Lessons\DTOs\LessonTypeOption $resource
 */
class LessonTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'color' => $this->color,
            'sort' => $this->sort,
            'next_lesson_threshold' => $this->nextLessonThreshold,
            'time_gap' => $this->timeGap,
        ];
    }
}
