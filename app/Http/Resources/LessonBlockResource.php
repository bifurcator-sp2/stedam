<?php

namespace App\Http\Resources;

use App\Blocks\Settings\SettingsNormalizer;
use Illuminate\Http\Resources\Json\JsonResource;

class LessonBlockResource extends JsonResource
{
    public function toArray($request): array
    {

        $settings = SettingsNormalizer::fillProcTests($this->resource);
        return [
            'id' => $this->id,
            'block_id' => $this->block_id,
            'block_type_code' => $this->block?->code,
            'settings' => SettingsNormalizer::settingsToS3urls(SettingsNormalizer::fillPublicDefaults(
                $settings ?? [],
                $this->block?->settings ?? [],
            )),
            'order' => $this->pivot?->order ?? $this->order,
            'is_test' => $this->lesson_id === null || (bool)($this->block?->is_test),
            'is_proc' => (bool)($this->is_proc),
            'user_id' => $this->user_id,
            'usages' => $this->whenCounted('usages'),
            'taxonomy_ids' => $this->whenLoaded(
                'taxonomies',
                fn() => $this->taxonomies->pluck('id')->all(),
                []
            ),
            'complexity'   => (float) $this->complexity,
        ];
    }
}
