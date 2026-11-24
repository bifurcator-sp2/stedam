<?php

namespace App\Http\Resources;


use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Элемент списка уроков (лёгкая проекция).
 *
 * Справочник `lesson_types` — на уровне ответа списка (см. LessonController::list):
 * дублировать его в каждом элементе незачем.
 *
 * @property \App\Models\Lesson $resource
 */
class LessonListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type,
            'blocks_count' => $this->whenCounted('blocks'),
            'published' => (bool) $this->published,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            // Возвращаем массив ID таксономий
            'taxonomy_ids' => $this->whenLoaded('taxonomies', fn () => $this->taxonomies->pluck('id')->toArray()),
            // Срез «актуальных уроков» для текущего ученика (unassigned/
            // assigned/continue/grades). Вычисляется только в списках,
            // где он реально нужен; иначе ключа в ответе нет.
            'actual_lesson_scope' => $this->when(
                $this->getAttribute('actual_lesson_scope') !== null,
                fn () => $this->getAttribute('actual_lesson_scope'),
            ),
            // Оценка по уроку ({grade, status}) — только для среза grades.
            'grade' => $this->when(
                $this->getAttribute('actual_lesson_grade') !== null,
                fn () => $this->getAttribute('actual_lesson_grade'),
            ),
        ];
    }
}
