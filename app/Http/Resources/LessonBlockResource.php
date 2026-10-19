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

    public static function fillPublicDefaults($settings, $schema)
    {
        // ---- нормализуем схему ----
        if (is_object($schema)) {
            $wasObject = true;
            $schema    = (array) $schema;
        } elseif (is_array($schema)) {
            $wasObject = false;
        } else {
            // Скалярная схема — это шаблон-значение. Побеждает settings (данные пользователя).
            return $settings ?? $schema;
        }

        // ---- FAST PATH 1: пустая схема = слот данных ("default": []) ----
        // Здесь нечего форсить (public у элементов данных не участвует в схеме),
        // поэтому просто возвращаем settings. COW → копий нет вообще.
        if ($schema === []) {
            if ($settings === null) {
                return $wasObject ? (object) [] : [];
            }
            return $settings;
        }

        // ---- нормализуем settings ----
        if (is_object($settings)) {
            $settings = (array) $settings;
        } elseif (!is_array($settings)) {
            $settings = [];
        }

        // public всегда берём из схемы; из settings выкидываем, только если он там есть
        if (array_key_exists('public', $settings)) {
            unset($settings['public']);
        }

        // ---- FAST PATH 2: settings пустой → только форсим public по схеме ----
        if ($settings === []) {
            return self::forcePublic($schema, $wasObject);
        }

        // ---- общий случай ----
        $result = [];
        foreach ($schema as $key => $schemaVal) {
            if ($key === 'public') {
                continue;
            }

            $hasSetting = array_key_exists($key, $settings);

            if (is_array($schemaVal) || is_object($schemaVal)) {
                $result[$key] = self::fillPublicDefaults(
                    $hasSetting ? $settings[$key] : null,
                    $schemaVal
                );
            } else {
                // Скалярный шаблон — данные из settings имеют приоритет
                $result[$key] = $hasSetting ? $settings[$key] : $schemaVal;
            }
        }

        if (array_key_exists('public', $schema)) {
            $result['public'] = $schema['public'] ? true : $schema['public'];
        }

        return $wasObject ? (object) $result : $result;
    }

    private static function forcePublic(array $schema, bool $wasObject): array|object
    {
        if ($schema === []) {
            return $wasObject ? (object) [] : [];
        }
        $result = [];
        foreach ($schema as $key => $val) {
            if ($key === 'public') {
                continue;
            }
            if (is_object($val)) {
                $result[$key] = self::forcePublic((array) $val, true);
            } elseif (is_array($val)) {
                $result[$key] = self::forcePublic($val, false);
            } else {
                $result[$key] = $val;
            }
        }
        if (array_key_exists('public', $schema)) {
            $result['public'] = $schema['public'] ? true : $schema['public'];
        }
        return $wasObject ? (object) $result : $result;
    }

}
