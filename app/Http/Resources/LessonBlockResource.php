<?php

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;

class LessonBlockResource extends JsonResource
{
    public function toArray($request): array
    {

        return [
            'id'              => $this->id,
            'block_id'        => $this->block_id,
            'block_type_code' => $this->block?->blockType?->code,
            'settings'        => self::fillPublicDefaults($this->settings ?? [], $this->block->settings??[]),
            'order'           => $this->order,
        ];

    }

    public static function fillPublicDefaults($settings, $schema){

        if (is_array($schema)) {
            if (array_is_list($schema)) {

                foreach ($schema as $key => $val) {
                    if (array_key_exists($key, $settings)) {
                        $schema[$key] = self::fillPublicDefaults($settings[$key], $val);
                    }
                }
            } else {
                foreach ($schema as $key => $val) {
                    if (array_key_exists($key, $settings)) {
                        $schema[$key] = self::fillPublicDefaults($settings[$key], $val);
                    }
                }
                if (!empty($schema['public'])) {
                    if (array_key_exists('default', $schema) && array_key_exists('default', $settings)) {
                        $schema['default'] = $settings['default'];
                    }
                }
            }
        }
        return $schema;
    }

}
