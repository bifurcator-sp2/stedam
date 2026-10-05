<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'block_type_id' => $this->block_type_id,
            'code'          => $this->blockType?->code,
            'name'          => $this->blockType?->name,
            'title'         => $this->title,
            'description'   => $this->description,
            'settings'      => $this->settings ?? [],
            'created_at'    => $this->created_at?->toISOString(),
            'updated_at'    => $this->updated_at?->toISOString(),
        ];
    }
}
