<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LessonBlockResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'block_id'        => $this->block_id,
            'block_type_code' => $this->block?->blockType?->code,
            'settings'        => $this->settings,
            'images'          => $this->storedImages(),
            'files'           => $this->storedPlainFiles(),
            'order'           => $this->order,
        ];
    }
}
