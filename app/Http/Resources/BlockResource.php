<?php

namespace App\Http\Resources;

use App\Blocks\Settings\BlockSettingsRegistry;
use App\Blocks\Settings\SettingsNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'code'          => $this->code,
            'title'         => $this->title,
            'description'   => $this->description,
            'settings'      => SettingsNormalizer::settingsToS3urls(SettingsNormalizer::updateBySchema($this->settings ?? [], BlockSettingsRegistry::serializeSchema($this->code))), //SettingsNormalizer::mergeSchemaWithSaved(BlockSettingsRegistry::serializeSchema($this->code), $this->settings ?? []),
            'published'     => (bool) $this->published,

            // Список id узлов BlockIndex, к которым привязан блок.
            // Если отношение не загружено (forgot ->with('blockIndexes')) —
            // вернётся пустой массив, без N+1.
            'parent_block_index_ids' => $this->whenLoaded(
                'blockIndexes',
                fn () => $this->blockIndexes->pluck('id')->values()->all(),
                [],
            ),
            'is_test' => (bool) $this->is_test,
            'created_at'    => $this->created_at?->toISOString(),
            'updated_at'    => $this->updated_at?->toISOString(),
        ];
    }
}
