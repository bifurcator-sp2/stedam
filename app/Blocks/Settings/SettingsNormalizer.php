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

            foreach ($schema as $schemaKey => $schemaVal) {
                if (in_array($schemaKey, ['label', 'public', 'key', 'type', 'children'], true)) {
                    continue;
                }
                if (array_key_exists($schemaKey, $saved)) {
                    $merged[$schemaKey] = $saved[$schemaKey];
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

            foreach ($schemaItem as $schemaKey => $schemaVal) {
                if (in_array($schemaKey, ['label', 'public', 'key', 'type', 'children'], true)) {
                    continue;
                }
                if (array_key_exists($schemaKey, $savedItem)) {
                    $merged[$schemaKey] = $savedItem[$schemaKey];
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
    /**
     * Приводит структуру $saved к структуре $schema.
     *
     * - Анализирует $saved на всю глубину через children.
     * - Если в $schema появились children, которых нет в $saved — добавляет их (из $schema).
     * - Если в $saved есть children, которых больше нет в $schema — удаляет их.
     * - Сохраняет значения из $saved для существующих узлов.
     * - Трогает только иерархию children, не меняя другие поля узлов.
     */
    public static function updateBySchema(?array $saved, ?array $schema): array
    {
        if (!is_array($schema)) {
            return $saved ?? [];
        }

        // ── Единичный корневой узел ──
        if (isset($schema['key'])) {
            if (is_array($saved) && array_is_list($saved) && isset($saved[0]['key'])) {
                $saved = $saved[0];
            }

            if (!is_array($saved) || !isset($saved['key']) || $saved['key'] !== $schema['key']) {
                return $schema;
            }

            return self::updateNodeChildren($saved, $schema);
        }

        // ── Список узлов ──
        if (is_array($saved) && isset($saved['key'])) {
            $saved = [$saved];
        }

        if (!is_array($saved) || !array_is_list($saved)) {
            $saved = [];
        }

        $savedByKey = [];
        foreach ($saved as $item) {
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
            if ($key === null) {
                $result[] = $schemaItem;
                continue;
            }

            $savedItem = $savedByKey[$key] ?? null;

            if ($savedItem === null) {
                $result[] = $schemaItem;
            } else {
                $result[] = self::updateNodeChildren($savedItem, $schemaItem);
            }
        }

        return $result;
    }

    /**
     * Рекурсивно обновляет children узла $savedNode по эталону $schemaNode.
     */
    protected static function updateNodeChildren(array $savedNode, array $schemaNode): array
    {
        $schemaChildren = $schemaNode['children'] ?? null;

        // Если в новой схеме у узла нет детей, убираем их из saved
        if (!is_array($schemaChildren) || empty($schemaChildren)) {
            unset($savedNode['children']);
            return $savedNode;
        }

        $savedChildren = $savedNode['children'] ?? [];
        if (!is_array($savedChildren)) {
            $savedChildren = [];
        }

        $savedByKey = [];
        foreach ($savedChildren as $child) {
            if (is_array($child) && isset($child['key'])) {
                $savedByKey[$child['key']] = $child;
            }
        }

        $newChildren = [];

        // Идем по схеме — это эталон структуры и порядка
        foreach ($schemaChildren as $schemaChild) {
            if (!is_array($schemaChild) || !isset($schemaChild['key'])) {
                continue;
            }

            $key = $schemaChild['key'];

            if (isset($savedByKey[$key])) {
                // Узел есть в обоих — рекурсивно обновляем его детей
                $newChildren[] = self::updateNodeChildren($savedByKey[$key], $schemaChild);
            } else {
                // Узла нет в saved — добавляем из схемы (с дефолтами)
                $newChildren[] = $schemaChild;
            }
        }

        $savedNode['children'] = $newChildren;
        return $savedNode;
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


    /**
     * Возвращает сокращённое дерево defaults только публичных узлов.
     *
     * Обходит $schema на всю глубину и оставляет в ответе для каждого
     * "нужного" узла только:
     *   - key
     *   - public (только если в схеме public === true)
     *   - default (значение default из соответствующего узла $settings)
     *   - children (рекурсивно, если есть)
     *
     * "Нужный" узел — тот, у которого в схеме public=true, либо тот,
     * у которого в поддереве есть хотя бы один публичный узел.
     * Так контейнеры без public сохраняются как путь к публичным листьям.
     *
     * Формат выхода совпадает по shape с $schema:
     *   single-root (есть key на верхнем уровне) → single-узел или []
     *   list узлов                                → list узлов
     *
     * @throws SettingsShapeException при фатальных расхождениях формы
     */
    public static function extractPublicDefaults(?array $settings, ?array $schema): array
    {
        return self::extractPublicDefaultsAt($settings, $schema, []);
    }

    /**
     * Внутренняя реализация с прокидыванием $path для диагностики.
     */
    protected static function extractPublicDefaultsAt(?array $settings, ?array $schema, array $path): array
    {
        if (!is_array($schema)) {
            return [];
        }

        $settings = is_array($settings) ? $settings : [];

        $schemaIsSingle   = isset($schema['key']);
        $settingsIsSingle = isset($settings['key']);

        // ── Единичный корневой узел ──
        if ($schemaIsSingle) {
            if ($settingsIsSingle) {
                // ок
            } elseif (array_is_list($settings)) {
                if (count($settings) > 1) {
                    throw new SettingsShapeException(
                        $path,
                        'schema is single-node, but settings is a list with multiple items',
                        'single node',
                        'list of ' . count($settings) . ' items',
                    );
                }

                if (count($settings) === 1) {
                    if (!is_array($settings[0]) || !isset($settings[0]['key'])) {
                        throw new SettingsShapeException(
                            array_merge($path, ['#0']),
                            'settings list item is not a node (missing key)',
                            'node with key',
                            $settings[0],
                        );
                    }
                    $settings = $settings[0];
                } else {
                    $settings = [];
                }
            } elseif (!empty($settings)) {
                throw new SettingsShapeException(
                    $path,
                    'schema is single-node, but settings has incompatible shape',
                    'node or list',
                    $settings,
                );
            } else {
                $settings = [];
            }

            $node = self::extractPublicDefaultsNode($schema, $settings, $path);

            return $node ?? [];
        }

        // ── Список узлов ──
        if ($settingsIsSingle) {
            throw new SettingsShapeException(
                $path,
                'schema is a list, but settings is a single node',
                'list of nodes',
                'single node',
            );
        }

        if (!array_is_list($settings) && !empty($settings)) {
            throw new SettingsShapeException(
                $path,
                'schema is a list, but settings is not a list',
                'list of nodes',
                $settings,
            );
        }

        $settingsByKey = [];

        foreach ($settings as $i => $item) {
            if (!is_array($item)) {
                throw new SettingsShapeException(
                    array_merge($path, ["#{$i}"]),
                    'settings list item is not an array',
                    'array node',
                    $item,
                );
            }

            $key = $item['key'] ?? null;
            if (!is_string($key) || $key === '') {
                throw new SettingsShapeException(
                    array_merge($path, ["#{$i}"]),
                    'settings list item is missing string key',
                    'node with string key',
                    $item,
                );
            }

            if (isset($settingsByKey[$key])) {
                throw new SettingsShapeException(
                    array_merge($path, [$key]),
                    'duplicate key in settings list',
                    'unique key',
                    $key,
                );
            }

            $settingsByKey[$key] = $item;
        }

        $result = [];

        foreach ($schema as $schemaItem) {
            if (!is_array($schemaItem)) {
                continue;
            }

            $key = $schemaItem['key'] ?? null;
            if ($key === null) {
                continue;
            }

            $settingsItem = $settingsByKey[$key] ?? [];
            $nodePath     = array_merge($path, [$key]);

            $node = self::extractPublicDefaultsNode($schemaItem, $settingsItem, $nodePath);
            if ($node !== null) {
                $result[] = $node;
            }
        }

        return $result;
    }

    /**
     * Обрабатывает один узел схемы. Возвращает null, если узел не нужен.
     */
    protected static function extractPublicDefaultsNode(array $schemaNode, array $settingsNode, array $path): ?array
    {
        $key = $schemaNode['key'] ?? null;
        if ($key === null) {
            return null;
        }

        $isPublic = !empty($schemaNode['public']);

        $schemaChildren       = $schemaNode['children'] ?? null;
        $hasSchemaChildren    = is_array($schemaChildren) && !empty($schemaChildren);

        $settingsChildren     = $settingsNode['children'] ?? null;
        $hasSettingsChildren  = $settingsChildren !== null;

        if ($hasSettingsChildren && !is_array($settingsChildren)) {
            throw new SettingsShapeException(
                array_merge($path, ['children']),
                'settings children is not an array',
                'array',
                $settingsChildren,
            );
        }

        if ($hasSettingsChildren && !$hasSchemaChildren && !empty($settingsChildren)) {
            throw new SettingsShapeException(
                array_merge($path, ['children']),
                'settings has children, but schema node is a leaf',
                'leaf (no children)',
                $settingsChildren,
            );
        }

        $children = [];
        if ($hasSchemaChildren) {
            $childSettings = is_array($settingsChildren) ? $settingsChildren : [];
            $children = self::extractPublicDefaultsAt(
                $childSettings,
                $schemaChildren,
                array_merge($path, ['children']),
            );
        }

        if (!$isPublic && empty($children)) {
            return null;
        }

        $result = ['key' => $key];

        if ($isPublic) {
            $result['public'] = true;
        }

        $result['default'] = $settingsNode['default'] ?? null;

        if (!empty($children)) {
            $result['children'] = $children;
        }

        return $result;
    }

    /**
     * Заполняет схему значениями из снимка публичных defaults.
     *
     * Обратная операция к extractPublicDefaults: берёт $schema как эталон
     * структуры и рекурсивно перезаписывает в её узлах:
     *   - default — значением default из соответствующего узла $settings
     *   - public  — значением public из соответствующего узла $settings
     *
     * Узлы, которых нет в $settings, остаются как в $schema (не трогаем).
     * Быстро, без анализа и исключений — при несовпадении shape узел
     * просто пропускается, схема возвращается частично заполненной.
     *
     * Идемпотентна: повторный вызов с тем же результатом не меняет его.
     */
    public static function fillPublicDefaults(?array $settings, ?array $schema): array
    {
        if (!is_array($schema) || empty($schema)) {
            return is_array($schema) ? $schema : [];
        }

        if (!is_array($settings) || empty($settings)) {
            return $schema;
        }

        // ── Единичный корневой узел ──
        if (isset($schema['key'])) {
            // settings пришёл как list с одним элементом
            if (array_is_list($settings) && isset($settings[0]['key'])) {
                if (count($settings) !== 1) {
                    return $schema;
                }
                $settings = $settings[0];
            }

            if (!is_array($settings) || !isset($settings['key'])) {
                return $schema;
            }

            if ($settings['key'] !== $schema['key']) {
                return $schema;
            }

            return self::fillPublicDefaultsNode($schema, $settings);
        }

        // ── Список узлов ──
        // settings пришёл как single-node — обернём в список
        if (isset($settings['key'])) {
            $settings = [$settings];
        }

        if (!is_array($settings)) {
            return $schema;
        }

        $settingsByKey = [];
        foreach ($settings as $item) {
            if (is_array($item) && !empty($item['key'])) {
                $settingsByKey[$item['key']] = $item;
            }
        }

        if (empty($settingsByKey)) {
            return $schema;
        }

        $result = [];

        foreach ($schema as $schemaItem) {
            if (!is_array($schemaItem)) {
                $result[] = $schemaItem;
                continue;
            }

            $key = $schemaItem['key'] ?? null;
            $settingsItem = $key !== null ? ($settingsByKey[$key] ?? null) : null;

            if ($settingsItem === null) {
                $result[] = $schemaItem;
                continue;
            }

            $result[] = self::fillPublicDefaultsNode($schemaItem, $settingsItem);
        }

        return $result;
    }

    /**
     * Накатывает default/public из одного узла снимка на один узел схемы
     * и рекурсивно идёт в children.
     */
    protected static function fillPublicDefaultsNode(array $schemaNode, array $settingsNode): array
    {
        $merged = $schemaNode;

        if (array_key_exists('default', $settingsNode)) {
            $merged['default'] = $settingsNode['default'];
        }

        if (array_key_exists('public', $settingsNode)) {
            $merged['public'] = $settingsNode['public'];
        }

        $schemaChildren   = $schemaNode['children'] ?? null;
        $settingsChildren = $settingsNode['children'] ?? null;

        if (is_array($schemaChildren) && !empty($schemaChildren)
            && is_array($settingsChildren) && !empty($settingsChildren)
        ) {
            $merged['children'] = self::fillPublicDefaults($settingsChildren, $schemaChildren);
        }

        return $merged;
    }
}
