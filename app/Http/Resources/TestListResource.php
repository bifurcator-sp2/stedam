<?php

namespace App\Http\Resources;

use App\Blocks\Settings\SettingsNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TestListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'user_id'         => $this->user_id,
            'block_type_code' => $this->block?->code,
            'settings'        => SettingsNormalizer::fillPublicDefaults($this->settings ?? [], $this->block->settings ?? []),
            'usages'          => $this->whenCounted('usages'), // <--- ДОБАВЛЕНО
            'taxonomy_ids'    => $this->whenLoaded(
                'taxonomies',
                fn() => $this->taxonomies->pluck('id')->all(),
                []
            ),
            'created_at'      => $this->created_at?->toISOString(),
            'updated_at'      => $this->updated_at?->toISOString(),
        ];
    }
}
