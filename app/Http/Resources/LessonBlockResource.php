<?php

namespace App\Http\Resources;

use App\Blocks\Settings\SettingsNormalizer;
use Illuminate\Http\Resources\Json\JsonResource;

class LessonBlockResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'block_id'        => $this->block_id,
            'block_type_code' => $this->block?->code, // <-- Изменено
            'settings'        => SettingsNormalizer::mergeSchemaWithSaved(
                $this->block?->settings ?? [],
                $this->settings ?? [],
            ),
            'order'           => $this->order,
        ];
    }
}
