<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LessonResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'title'        => $this->title,
            'description'  => $this->description,
            'locale'       => $this->locale,
            'saved'        => (bool) $this->saved,
            'published'    => (bool) $this->published,

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
        ];
    }
}
