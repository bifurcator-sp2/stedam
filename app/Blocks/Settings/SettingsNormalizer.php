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
     * @param array|null $saved Сохранённые значения (массив узлов).
     * @return array
     */
    public static function mergeSchemaWithSaved(?array $schema, ?array $saved): array
    {
        if (!is_array($schema)) {
            return $saved ?? [];
        }


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

            if ($savedItem === null) {
                $result[] = $schemaItem;
                continue;
            }

            // Мерж: базовая структура — из схемы, `default` — из saved
            $merged = $schemaItem;


            foreach ($schemaItem as $schenaKey => $schemaVal) {
                if (
                    $schenaKey == 'label' || $schenaKey == 'public'
                ) {
                    continue;
                }
                if (array_key_exists($schenaKey, $savedItem)) {
                    $merged[$schenaKey] = $savedItem[$schenaKey];
                }
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
        return self::mergeSchemaWithSaved(BlockSettingsRegistry::serializeSchema($code), $saved ?? []);
    }

    public static function normalizeUrls(array $settings): array
    {
        $result = [];

        foreach ($settings as $key => $value) {
            if (is_array($value)) {
                $result[$key] = self::normalizeUrls($value);
                continue;
            }

            if (is_string($value)) {
                $result[$key] = self::normalizeUrlToPath($value);
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }




    public static function normalizeUrlToPath(string $value): string
    {
        // Если это не http/https — не трогаем (blob:, data:, относительный путь)
        if (!preg_match('#^https?://#i', $value)) {
            return $value;
        }

        // Просто вырезаем протокол и домен, оставляя путь (с ведущим слешем)
        $parsed = parse_url($value, PHP_URL_PATH);

        if (is_string($parsed) && str_starts_with($parsed, '/')) {
            return $parsed;
        }

        return $value;
    }

    /**
     * Рекурсивно заполняет схему значениями из настроек.
     *
     * Проходит по схеме и для каждого ключа, присутствующего в `$settings`,
     * рекурсивно применяет ту же логику (глубокое слияние по структуре схемы).
     *
     * Поведение зависит от типа узла схемы:
     *  - Список (индексированный массив) — рекурсивно обрабатывается по индексам.
     *  - Ассоциативный массив — рекурсивно обрабатывается по ключам, а также
     *    дополнительно переносит `default` из настроек, если узел помечен
     *    как публичный (`public` не пустой) и в обоих массивах есть ключ `default`.
     *  - Скаляр/иное — возвращается без изменений.
     *
     * @param array|mixed $settings Настройки (источник значений). Ожидается массив
     *                              на верхнем уровне; вложенные значения могут быть
     *                              любого типа.
     * @param array|mixed $schema     Схема (шаблон структуры). Ожидается массив
     *                                на верхнем уровне; вложенные значения могут быть
     *                                любого типа.
     *
     * @return array|mixed Схема с подставленными значениями из `$settings`.
     *                     Если `$schema` не массив — возвращается как есть.
     */
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

    /**
     * Рекурсивно извлекает из настроек значения, описанные в схеме.
     *
     * Является обратной операцией к {@see self::fillPublicDefaults()}: вместо того
     * чтобы подставлять значения из `$settings` в `$schema`, она строит новый
     * массив, содержащий только те ключи, которые присутствуют в `$schema`,
     * со значениями из `$settings`.
     *
     * Поведение зависит от типа узла схемы:
     *  - Список (индексированный массив) — рекурсивно обходится по индексам;
     *    в результат попадают только существующие в `$settings` индексы.
     *  - Ассоциативный массив — рекурсивно обходится по ключам; дополнительно
     *    переносит `default` из настроек, если узел помечен как публичный
     *    (`public` не пустой) и в обоих массивах есть ключ `default`.
     *  - Скаляр/иное — возвращается значение из `$settings` (или `null`, если
     *    ключа нет на уровне вызывающего кода; см. примечание).
     *
     * @param array|mixed $settings Настройки (источник значений). Ожидается массив
     *                              на верхнем уровне; вложенные значения могут быть
     *                              любого типа.
     * @param array|mixed $schema     Схема (шаблон структуры). Ожидается массив
     *                                на верхнем уровне; вложенные значения могут быть
     *                                любого типа.
     *
     * @return array|mixed Новый массив, содержащий только ключи из `$schema`,
     *                     со значениями из `$settings`. Если `$schema` не массив —
     *                     возвращается `$settings` как есть.
     */
    public static function extractPublicValues($settings, $schema)
    {
        if (!is_array($schema)) {
            return $settings;
        }

        $result = [];
        $isList = array_is_list($schema);

        foreach ($schema as $key => $val) {
            if (is_array($settings) && array_key_exists($key, $settings)) {
                $result[$key] = self::extractPublicValues($settings[$key], $val);
            }
        }

        if (!$isList
            && !empty($schema['public'])
            && array_key_exists('default', $schema)
            && is_array($settings)
            && array_key_exists('default', $settings)
        ) {
            $result['default'] = $settings['default'];
        }

        return $result;
    }
}
