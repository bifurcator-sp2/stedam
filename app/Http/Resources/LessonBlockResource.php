<?php

namespace App\Http\Resources;

use App\Blocks\Settings\SettingsNormalizer;
use Illuminate\Http\Resources\Json\JsonResource;

class LessonBlockResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'block_id' => $this->block_id,
            'block_type_code' => $this->block?->code,
            'settings' => SettingsNormalizer::fillPublicDefaults(
                $this->settings ?? [],
                $this->block?->settings ?? [],
            ),
            'order' => $this->order,
        ];
    }
}
