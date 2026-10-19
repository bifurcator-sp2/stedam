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
        // 1. Запоминаем, был ли объект, чтобы вернуть правильный тип в конце
        $wasObject = is_object($schema);

        // 2. Приводим к массивам для удобной работы
        // (null или строка превратятся в пустой массив)
        $schema = (array) $schema;
        $settings = (array) $settings;

        // Если схема пустая (например, "default": []), то это чистый слот для данных.
        // Просто возвращаем то, что пришло в настройках, сохраняя тип.
        if (empty($schema)) {
            return $wasObject ? (object) $settings : $settings;
        }

        $result = [];

        // 3. Идем по ключам СХЕМЫ
        foreach ($schema as $key => $schemaVal) {
            if ($key === 'public') {
                continue; // Обработаем в самом конце
            }

            // --- ГЛАВНОЕ ПРАВИЛО ---
            // Если ключ называется 'default', мы НЕ крутим его вглубь.
            // Мы просто берем значение из настроек. Если его там нет — оставляем из схемы.
            if ($key === 'default') {
                $result['default'] = array_key_exists('default', $settings) ? $settings['default'] : $schemaVal;
                continue;
            }

            // --- ДЛЯ ВСЕХ ОСТАЛЬНЫХ КЛЮЧЕЙ ---
            $hasSetting = array_key_exists($key, $settings);

            if (is_array($schemaVal) || is_object($schemaVal)) {
                // Если это вложенный массив/объект — идем в рекурсию
                $result[$key] = self::fillPublicDefaults(
                    $hasSetting ? $settings[$key] : null,
                    $schemaVal
                );
            } else {
                // Если это скаляр (строка, число, bool) — берем из настроек, иначе из схемы
                $result[$key] = $hasSetting ? $settings[$key] : $schemaVal;
            }
        }

        // 4. Возвращаем 'public' на место, если он был в схеме
        if (array_key_exists('public', $schema)) {
            // Принудительно делаем true, если в схеме true
            $result['public'] = $schema['public'] ? true : $schema['public'];
        }

        return $wasObject ? (object) $result : $result;
    }



}
