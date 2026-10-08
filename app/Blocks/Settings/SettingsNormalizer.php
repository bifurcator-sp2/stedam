<?php

namespace App\Blocks\Settings;

class SettingsNormalizer
{


    /**
     * Мержит актуальную схему (schema) с сохранёнными значениями (saved).
     * Порядок и структура берутся из schema, а `default` — из saved, если ключ найден.
     * Новые поля появляются автоматически, удалённые — исчезают.
     *
     * @param array|null $schema Актуальная схема (массив узлов).
     * @param array|null $saved  Сохранённые значения (массив узлов).
     * @return array
     */
    public static function mergeSchemaWithSaved(?array $schema, ?array $saved): array
    {
        if (!is_array($schema)) {
            return $saved ?? [];
        }

        // Индексируем сохранённые узлы по ключу
        $savedByKey = [];
        foreach ($saved ?? [] as $item) {
            if (!empty($item['key'])) {
                $savedByKey[$item['key']] = $item;
            }
        }

        $result = [];

        foreach ($schema as $schemaItem) {
            $key = $schemaItem['key'] ?? null;
            $savedItem = $key !== null ? ($savedByKey[$key] ?? null) : null;

            // Нет сохранённого — берём схему как есть
            if ($savedItem === null) {
                $result[] = $schemaItem;
                continue;
            }

            // Мерж: базовая структура — из схемы, `default` — из saved
            $merged = $schemaItem;

            if (array_key_exists('default', $savedItem)) {
                $merged['default'] = $savedItem['default'];
            }

            // Рекурсивно мержим children
            if (!empty($schemaItem['children']) && is_array($schemaItem['children'])) {
                $merged['children'] = self::mergeSchemaWithSaved(
                    $schemaItem['children'],
                    $savedItem['children'] ?? [],
            );
            }

            $result[] = $merged;
        }

        return $result;
    }

    public static function normalize(?string $code, ?array $saved): array
    {
        return self::mergeSchemaWithSaved(BlockSettingsRegistry::serializeSchema($code), $saved??[]);
    }


}
