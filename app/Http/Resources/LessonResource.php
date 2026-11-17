<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @property \App\Models\Lesson $resource
 */
class LessonResource extends JsonResource
{
    /**
     * Справочник типов для select'а; null — ключ `lesson_types` не сериализуется.
     *
     * @var list<\App\Application\Lessons\DTOs\LessonTypeOption>|null
     */
    private ?array $lessonTypes = null;

    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'title'        => $this->title,
            'description'  => $this->description,
            'locale'       => $this->locale,
            'type'         => $this->type,
            'saved'        => (bool) $this->saved,
            'published'    => (bool) $this->published,
            'complexity'   => (float) $this->complexity,
            'blocks'       => LessonBlockResource::collection(
                $this->when(
                    $this->relationLoaded('blocks') || $this->relationLoaded('lessonBlockAsTests'),
                    fn () => $this->allBlocksOrdered()
                )
            ),

            'taxonomy_ids' => $this->whenLoaded(
                'taxonomies',
                fn () => $this->taxonomies->pluck('id')->all(),
                []
            ),

            // Справочник типов для select'а: отдаётся только для ответа show,
            // где его явно передаёт контроллер; в остальных ответах ключа нет.
            'lesson_types' => $this->when(
                $this->lessonTypes !== null,
                fn () => array_map(
                    fn ($option) => (new LessonTypeResource($option))->resolve($request),
                    $this->lessonTypes,
                )
            ),
        ];
    }

    /**
     * Проекция для ответов урока с заранее собранным справочником типов.
     *
     * Справочник — DTO Application-слоя (см. LessonTypeQueryInterface),
     * а не модель: HTTP-слой к таблице `lesson_types` не обращается.
     *
     * @param \Illuminate\Support\Collection<int, \App\Application\Lessons\DTOs\LessonTypeOption> $lessonTypes
     */
    public function withLessonTypes(Collection $lessonTypes): self
    {
        $this->lessonTypes = array_values($lessonTypes->all());

        return $this;
    }
}
