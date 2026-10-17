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

            /*if (array_key_exists('default', $savedItem)) {
                $merged['default'] = $savedItem['default'];
                $merged['public'] = $savedItem['public'];
            }*/
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
    /**
     * Применяет плоские переопределения (объект с путями через точку)
     * к дереву SettingNode[].
     *
     * Используется, когда фронт присылает settings в «сжатом» виде:
     *   { "images": { "gallery": [...] }, "background-image": [] }
     *
     * А на бэке нужно дерево:
     *   [ { key: 'images', children: [ { key: 'gallery', default: [...] } ] }, ... ]
     *
     * @param array $schema Дерево узлов (обычно результат serializeSchema).
     * @param array $flat Плоский объект переопределений.
     * @return array Дерево с применёнными значениями.
     */
    public static function applyFlat(array $schema, array $flat): array
    {
        // Сначала нормализуем схему — чтобы гарантировать структуру узлов.
        $tree = self::mergeSchemaWithSaved($schema, []);

        foreach ($flat as $path => $value) {
            $tree = self::applyAtPath($tree, (string) $path, $value);
        }

        return $tree;
    }

    /**
     * Устанавливает значение по пути через точку в дереве узлов.
     *
     * Путь "images.gallery" означает: найти узел с key='images',
     * в его children — узел с key='gallery', установить default = $value.
     *
     * Если узел не найден — ничего не делаем.
     */
    protected static function applyAtPath(array $nodes, string $path, mixed $value): array
    {
        $parts = explode('.', $path);
        $head = array_shift($parts);

        foreach ($nodes as $i => $node) {
            if (!is_array($node)) {
                continue;
            }

            if (($node['key'] ?? null) !== $head) {
                continue;
            }

            if (empty($parts)) {
                // Дошли до конечного узла — ставим default
                $nodes[$i]['default'] = $value;
                return $nodes;
            }

            // Идём глубже в children
            if (!empty($node['children']) && is_array($node['children'])) {
                $nodes[$i]['children'] = self::applyAtPath(
                    $node['children'],
                    implode('.', $parts),
                    $value,
                );
            }

            return $nodes;
        }

        return $nodes;
    }
    /**
     * Преобразует абсолютные URL внутри settings в относительные пути.
     *
     * Рекурсивно обходит массивы (включая ассоциативные и списки)
     * и заменяет строки вида "http://host/storage/path/to/file.jpg"
     * на "path/to/file.jpg".
     *
     * Работает и с плоским объектом, и с деревом SettingNode[].
     */
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




    /**
     * Преобразует абсолютный URL в относительный URI (путь).
     *
     * http://stedam.localhost/storage/models/file_sets/...
     * превращается в:
     * /storage/models/file_sets/...
     *
     * Если строка не URL или не относится к нашему домену/диску,
     * возвращает как есть (blob: и прочее).
     */
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
     * Преобразует относительный путь в абсолютный URL диска.
     *
     * Пропускает уже абсолютные URL и blob:/data:-строки.
     */
    public static function pathToUrl(string $value): string
    {
        if (preg_match('#^(https?|blob|data):#i', $value)) {
            return $value;
        }

        $diskName = config('file-sets.store_disk', 'public');

        try {
            return \Illuminate\Support\Facades\Storage::disk($diskName)->url($value);
        } catch (\Throwable $e) {
            return $value;
        }
    }
}
