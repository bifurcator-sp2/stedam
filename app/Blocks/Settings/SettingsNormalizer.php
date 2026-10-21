<?php

namespace App\Blocks\Settings;

use Bifurcator\FileSets\FileStorageKeys;

class SettingsNormalizer
{
    /**
     * Мержит актуальную схему (schema) с сохранёнными значениями (saved).
     */
    public static function mergeSchemaWithSaved(?array $schema, ?array $saved): array
    {
        if (!is_array($schema)) {
            return $saved ?? [];
        }

        // ── Единичный корневой узел ──
        if (isset($schema['key'])) {
            if (is_array($saved) && array_is_list($saved) && isset($saved[0]['key'])) {
                $saved = $saved[0];
            }

            if (!is_array($saved) || !isset($saved['key'])) {
                return $schema;
            }

            $merged = $schema;

            foreach ($schema as $schenaKey => $schemaVal) {
                if (in_array($schenaKey, ['label', 'public', 'key', 'type', 'children'], true)) {
                    continue;
                }
                if (array_key_exists($schenaKey, $saved)) {
                    $merged[$schenaKey] = $saved[$schenaKey];
                }
            }

            if (!empty($schema['children']) && is_array($schema['children'])) {
                $merged['children'] = self::mergeSchemaWithSaved(
                    $schema['children'],
                    $saved['children'] ?? [],
                );
            }

            return $merged;
        }

        // ── Список узлов ──
        $savedByKey = [];
        foreach ($saved ?? [] as $item) {
            if (is_array($item) && !empty($item['key'])) {
                $savedByKey[$item['key']] = $item;
            }
        }

        $result = [];

        foreach ($schema as $schemaItem) {
            if (!is_array($schemaItem)) {
                $result[] = $schemaItem;
                continue;
            }

            $key = $schemaItem['key'] ?? null;
            $savedItem = $key !== null ? ($savedByKey[$key] ?? null) : null;

            if ($savedItem === null) {
                $result[] = $schemaItem;
                continue;
            }

            $merged = $schemaItem;

            foreach ($schemaItem as $schenaKey => $schemaVal) {
                if (in_array($schenaKey, ['label', 'public', 'key', 'type', 'children'], true)) {
                    continue;
                }
                if (array_key_exists($schenaKey, $savedItem)) {
                    $merged[$schenaKey] = $savedItem[$schenaKey];
                }
            }

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
        return self::mergeSchemaWithSaved(BlockSettingsRegistry::serializeSchema($code ?? ''), $saved ?? []);
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
        if (!preg_match('#^https?://#i', $value)) {
            return $value;
        }
        $parsed = parse_url($value, PHP_URL_PATH);
        if (is_string($parsed) && str_starts_with($parsed, '/')) {
            return $parsed;
        }
        return $value;
    }

    /**
     * Синхронизирует FileSet'ы модели с настройками (settings).
     */
    public static function syncFileSetsFromSettings(array &$settings, $model, ?string $code = null): void
    {
        if ($code === null) {
            // Заменили получение code через blockType на прямое поле code
            if (isset($model->code)) {
                $code = $model->code;
            } elseif (isset($model->block) && isset($model->block->code)) {
                $code = $model->block->code;
            }
        }

        if (!$code || !class_exists(BlockSettingsRegistry::class)) {
            return;
        }

        $schema = BlockSettingsRegistry::serializeSchema($code);
        if (empty($schema)) {
            return;
        }

        $imageNodes = [];
        self::collectImageNodes($schema, $imageNodes);

        foreach ($imageNodes as $found) {
            $node = $found['node'];
            $path = $found['path'];

            $purposeValue = $node['params']['purpose'] ?? null;
            if ($purposeValue instanceof \BackedEnum) {
                $purposeValue = $purposeValue->value;
            }

            if (!$purposeValue) {
                $allowed = $node['allowed'] ?? null;
                if (is_array($allowed) && isset($allowed[0])) {
                    $purposeValue = $allowed[0];
                    if ($purposeValue instanceof \BackedEnum) {
                        $purposeValue = $purposeValue->value;
                    }
                }
            }

            if (!$purposeValue) {
                continue;
            }

            $rawValue = self::getValueByPath($settings, $path);
            if ($rawValue === null) {
                continue;
            }

            $images = [];
            if (is_array($rawValue)) {
                if (array_key_exists('default', $rawValue) && is_array($rawValue['default'])) {
                    $images = $rawValue['default'];
                } else {
                    $images = $rawValue;
                }
            }

            $fileSet = $model->fileSet($purposeValue);

            // Перенос из временного FileSet'а (model_id = 0)
            $class = $model::fileSetModel();
            $stubFileSet = $class::where([
                'model'    => get_class($model),
                'model_id' => 0,
                'purpose'  => $purposeValue,
            ])->first();

            if ($stubFileSet && $stubFileSet->getKey() !== $fileSet->getKey()) {
                $fromDir = $stubFileSet->tempDir();
                $toDir   = $fileSet->tempDir();
                $tempStorage = app(FileStorageKeys::TEMP);

                if ($tempStorage->exists($fromDir)) {
                    $tempStorage->makeDirectory($toDir);
                    foreach ($tempStorage->files($fromDir) as $filePath) {
                        $filename = basename($filePath);
                        $tempStorage->move($filePath, "{$toDir}/{$filename}");
                    }
                    $tempStorage->deleteDirectory($fromDir);
                }
            }

            $fileSet->syncFilesFromRequest($images, null);

            $actualFiles = array_merge(
                $fileSet->storedImages(),
                $fileSet->storedPlainFiles()
            );

            if (empty($actualFiles) && !empty($images)) {
                continue;
            }

            if (is_array($rawValue) && array_key_exists('default', $rawValue)) {
                $rawValue['default'] = $actualFiles;
                self::setValueByPath($settings, $path, $rawValue);
            } else {
                self::setValueByPath($settings, $path, $actualFiles);
            }
        }
    }

    /**
     * Клонирует store-файлы из FileSet'ов другой модели в FileSet'ы этой модели.
     */
    public static function cloneFileSetsFromModel(array $settings, $model, $source, ?string $code = null): array
    {
        if (!$code || !class_exists(BlockSettingsRegistry::class)) {
            return $settings;
        }

        $schema = BlockSettingsRegistry::serializeSchema($code);
        if (empty($schema)) {
            return $settings;
        }

        $imageNodes = [];
        self::collectImageNodes($schema, $imageNodes);

        $storeStorage = app(FileStorageKeys::STORE);

        foreach ($imageNodes as $found) {
            $node = $found['node'];
            $path = $found['path'];

            $purposeValue = $node['params']['purpose'] ?? null;
            if ($purposeValue instanceof \BackedEnum) {
                $purposeValue = $purposeValue->value;
            }
            if (!$purposeValue) {
                continue;
            }

            $rawValue = self::getValueByPath($settings, $path);
            if ($rawValue === null) {
                continue;
            }

            $images = [];
            if (is_array($rawValue)) {
                if (array_key_exists('default', $rawValue) && is_array($rawValue['default'])) {
                    $images = $rawValue['default'];
                } else {
                    $images = $rawValue;
                }
            }
            if (empty($images)) {
                continue;
            }

            $sourceFileSet = $source->findFileSet($purposeValue);
            if (!$sourceFileSet) {
                continue;
            }

            $targetFileSet = $model->fileSet($purposeValue);

            $sourceStoreDir = $sourceFileSet->storeDir();
            $targetStoreDir = $targetFileSet->storeDir();

            if (!$storeStorage->exists($targetStoreDir)) {
                $storeStorage->makeDirectory($targetStoreDir);
            }

            $sourceImages = $sourceFileSet->imageList();
            $targetImages = $targetFileSet->imageList();

            $existingByOriginal = [];
            foreach ($targetImages as $t) {
                if (!empty($t['original'])) {
                    $existingByOriginal[$t['original']] = $t;
                }
            }

            $sourceByOriginal = [];
            foreach ($sourceImages as $s) {
                if (!empty($s['original'])) {
                    $sourceByOriginal[$s['original']] = $s;
                }
            }

            $requested = [];
            foreach ($images as $img) {
                $url = $img['url'] ?? null;
                if (!$url) continue;
                $name = basename(parse_url($url, PHP_URL_PATH) ?: $url);
                if ($name) {
                    $requested[$name] = $img;
                }
            }

            $newList = [];

            foreach ($requested as $name => $incomingItem) {
                if (isset($existingByOriginal[$name])) {
                    $entry = $existingByOriginal[$name];
                    $entry['title'] = $incomingItem['title'] ?? ($entry['title'] ?? null);
                    $newList[] = $entry;
                    continue;
                }

                if (!isset($sourceByOriginal[$name])) {
                    continue;
                }

                $sourceEntry = $sourceByOriginal[$name];

                $fromOriginal = "{$sourceStoreDir}/{$sourceEntry['original']}";
                $toOriginal   = "{$targetStoreDir}/{$sourceEntry['original']}";

                if (!$storeStorage->exists($toOriginal)) {
                    $storeStorage->copy($fromOriginal, $toOriginal);
                }

                $newThumb = null;
                if (!empty($sourceEntry['thumbnail'])) {
                    $fromThumb = "{$sourceStoreDir}/{$sourceEntry['thumbnail']}";
                    $toThumb   = "{$targetStoreDir}/{$sourceEntry['thumbnail']}";
                    if ($storeStorage->exists($fromThumb) && !$storeStorage->exists($toThumb)) {
                        $storeStorage->copy($fromThumb, $toThumb);
                    }
                    $newThumb = $sourceEntry['thumbnail'];
                }

                $newList[] = [
                    'original'  => $sourceEntry['original'],
                    'thumbnail' => $newThumb,
                    'title'     => $incomingItem['title'] ?? ($sourceEntry['title'] ?? null),
                ];
            }

            $targetFileSet->images = $newList ?: null;
            $targetFileSet->saveQuietly();

            $newUrlItems = array_merge(
                $targetFileSet->storedImages(),
                $targetFileSet->storedPlainFiles(),
            );

            if (is_array($rawValue) && array_key_exists('default', $rawValue)) {
                $rawValue['default'] = $newUrlItems;
                self::setValueByPath($settings, $path, $rawValue);
            } else {
                self::setValueByPath($settings, $path, $newUrlItems);
            }
        }

        return $settings;
    }

    /**
     * Рекурсивно собирает все узлы с type === 'image' и их пути.
     */
    protected static function collectImageNodes(array $nodes, array &$result, array $currentPath = []): void
    {
        if (isset($nodes['key'])) {
            $nodes = [$nodes];
        }

        foreach ($nodes as $node) {
            if (!is_array($node)) continue;

            $nodeKey = $node['key'] ?? null;
            $path = $nodeKey !== null ? array_merge($currentPath, [$nodeKey]) : $currentPath;

            if (($node['type'] ?? null) === 'image') {
                $result[] = ['node' => $node, 'path' => $path];
            }

            if (!empty($node['children']) && is_array($node['children'])) {
                self::collectImageNodes($node['children'], $result, $path);
            }
        }
    }

    /**
     * Достает значение (default) из узла по пути.
     */
    protected static function getValueByPath(array $root, array $path): mixed
    {
        if (empty($path)) {
            return null;
        }

        if (array_is_list($root)) {
            $rootKey = $path[0];
            $rootNode = null;
            foreach ($root as $node) {
                if (($node['key'] ?? null) === $rootKey) {
                    $rootNode = $node;
                    break;
                }
            }
            if (!$rootNode) return null;

            $remainingPath = array_slice($path, 1);
            return self::getValueByPath($rootNode, $remainingPath);
        }

        if (($root['key'] ?? null) === $path[0]) {
            $remainingPath = array_slice($path, 1);
            if (empty($remainingPath)) {
                return $root['default'] ?? null;
            }

            foreach ($root['children'] ?? [] as $child) {
                if (($child['key'] ?? null) === $remainingPath[0]) {
                    return self::getValueByPath($child, $remainingPath);
                }
            }
            return null;
        }

        return null;
    }

    /**
     * Записывает значение в поле default узла по пути.
     */
    protected static function setValueByPath(array &$root, array $path, mixed $value): void
    {
        if (empty($path)) return;

        if (array_is_list($root)) {
            $rootKey = $path[0];
            foreach ($root as &$node) {
                if (($node['key'] ?? null) === $rootKey) {
                    self::setValueByPath($node, $path, $value);
                    return;
                }
            }
            return;
        }

        if (($root['key'] ?? null) === $path[0]) {
            $remainingPath = array_slice($path, 1);
            if (empty($remainingPath)) {
                $root['default'] = $value;
                return;
            }

            if (!isset($root['children']) || !is_array($root['children'])) {
                $root['children'] = [];
            }

            foreach ($root['children'] as &$child) {
                if (($child['key'] ?? null) === $remainingPath[0]) {
                    self::setValueByPath($child, $remainingPath, $value);
                    return;
                }
            }
            return;
        }
    }
}
