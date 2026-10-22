<?php

namespace App\Http\Resources;


use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Элемент списка уроков (лёгкая проекция).
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
            'blocks_count' => $this->whenCounted('blocks'),
            'published' => (bool) $this->published,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            // Возвращаем массив ID таксономий
            'taxonomy_ids' => $this->whenLoaded('taxonomies', fn () => $this->taxonomies->pluck('id')->toArray()),
        ];
    }
}
