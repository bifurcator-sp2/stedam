<?php

namespace App\Http\Resources;

use App\Domain\Blocks\Settings\SettingsNormalizer;
use Illuminate\Http\Resources\Json\JsonResource;

class LessonBlockResource extends JsonResource
{
    public function toArray($request): array
    {
        // ЗНАЧЕНИЯ экземпляра (с сохранёнными настройками и сгенерированными
        // proc-задачами). Отдаём ТОЛЬКО то, что реально сохранено, в компактной
        // форме key/public/default/children — без добора полной схемы прототипа
        // (иначе один блок раздувается с ~1.5 КБ до ~65 КБ).
        // Недостающие узлы (например block1) фронт берёт из схемы по block_type_code.
        $settings = SettingsNormalizer::fillProcTests($this->resource);
        $compact  = SettingsNormalizer::compactStoredSettings($settings ?? []);

        return [
            'id' => $this->id,
            'block_id' => $this->block_id,
            'block_type_code' => $this->block?->code,
            'settings' => SettingsNormalizer::settingsToS3urls($compact),
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
