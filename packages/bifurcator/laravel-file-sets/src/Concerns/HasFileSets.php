<?php
// packages/bifurcator/laravel-file-sets/src/Concerns/HasFileSets.php

namespace Bifurcator\FileSets\Concerns;

use Bifurcator\FileSets\Contracts\FileSetContract;
use Bifurcator\FileSets\FileStorageKeys;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use InvalidArgumentException;

trait HasFileSets
{
    /**
     * Все наборы файлов, привязанные к модели.
     */
    public function fileSets(): MorphMany
    {
        return $this->morphMany(
            static::fileSetModel(),
            'owner',
            'model',
            'model_id',
        );
    }

    /**
     * Класс модели FileSet.
     */
    public static function fileSetModel(): string
    {
        $class = config('file-sets.file_set_model');

        if (!is_string($class) || !class_exists($class)) {
            throw new InvalidArgumentException(
                'config("file-sets.file_set_model") должен указывать на существующий класс.'
            );
        }

        if (!is_subclass_of($class, FileSetContract::class)) {
            throw new InvalidArgumentException(
                "Класс {$class} должен реализовывать " . FileSetContract::class
            );
        }

        return $class;
    }

    /**
     * Список разрешённых purpose для модели.
     */
    public static function filePurposes(): array
    {
        if (property_exists(static::class, 'filePurposes')) {
            return static::$filePurposes;
        }

        $enumClass = config('file-sets.file_purpose');

        if (is_string($enumClass) && enum_exists($enumClass)) {
            return $enumClass::cases();
        }

        return [];
    }

    /**
     * Разрешён ли purpose для этой модели.
     */
    public static function allowsFilePurpose(\BackedEnum|string $purpose): bool
    {
        $value = $purpose instanceof \BackedEnum ? $purpose->value : $purpose;

        foreach (static::filePurposes() as $allowed) {
            $allowedValue = $allowed instanceof \BackedEnum ? $allowed->value : $allowed;

            if ($allowedValue === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Получить (или создать) набор файлов указанного назначения.
     */
    public function fileSet(\BackedEnum|string $purpose): FileSetContract
    {
        if (!static::allowsFilePurpose($purpose)) {
            throw new InvalidArgumentException(
                'Модель ' . static::class . ' не поддерживает purpose: '
                . ($purpose instanceof \BackedEnum ? $purpose->value : $purpose)
            );
        }

        $value = $purpose instanceof \BackedEnum ? $purpose->value : $purpose;
        $class = static::fileSetModel();

        return $class::firstOrCreate([
            'model'    => static::class,
            'model_id' => $this->getKey(),
            'purpose'  => $value,
        ]);
    }

    /**
     * Найти существующий FileSet без создания.
     */
    public function findFileSet(\BackedEnum|string $purpose): ?FileSetContract
    {
        $value = $purpose instanceof \BackedEnum ? $purpose->value : $purpose;

        return $this->fileSets()
            ->where('purpose', $value)
            ->first();
    }

    /**
     * Каскадное удаление.
     */
    protected static function bootHasFileSets(): void
    {
        static::deleting(function ($model) {
            $isForce = method_exists($model, 'isForceDeleting')
                && $model->isForceDeleting();

            foreach ($model->fileSets()->withTrashed()->get() as $fileSet) {
                if ($isForce) {
                    $fileSet->forceDelete();
                } else {
                    $fileSet->delete();
                }
            }
        });
    }


    /**
     * Синхронизирует FileSet'ы модели с плоскими настройками (settings).
     *
     * ВАЖНО: $flatSettings передается по ссылке.
     * Метод заменяет временные (temp) URL на актуальные (stored) URL.
     */
    public function syncFileSetsFromSettings(array &$flatSettings, ?string $code = null): void
    {
        if ($code === null) {
            if (isset($this->blockType)) {
                $code = $this->blockType->code;
            } elseif (isset($this->block) && isset($this->block->blockType)) {
                $code = $this->block->blockType->code;
            }
        }

        if (!$code || !class_exists(\App\Blocks\Settings\BlockSettingsRegistry::class)) {
            return;
        }

        $schema = \App\Blocks\Settings\BlockSettingsRegistry::serializeSchema($code);
        if (empty($schema)) {
            return;
        }

        // 1. Собираем ВСЕ image-узлы из схемы
        $imageNodes = [];
        $this->collectImageNodes($schema, $imageNodes);

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

            $rawValue = $this->getValueByPath($flatSettings, $path);

            // Если узла нет в переданных настройках, пропускаем.
            if ($rawValue === null) {
                continue;
            }

            // ========================================================
            // ИЗВЛЕЧЕНИЕ картинок
            // ========================================================
            $images = [];
            if (is_array($rawValue)) {
                if (array_key_exists('default', $rawValue) && is_array($rawValue['default'])) {
                    $images = $rawValue['default'];
                } else {
                    $images = $rawValue;
                }
            }

            $fileSet = $this->fileSet($purposeValue);

            // Перенос из временного FileSet'а (model_id = 0)
            $class = static::fileSetModel();
            $stubFileSet = $class::where([
                'model'    => static::class,
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

            // Синхронизируем
            $fileSet->syncFilesFromRequest($images, null);

            // Забираем актуальные файлы
            $actualFiles = array_merge(
                $fileSet->storedImages(),
                $fileSet->storedPlainFiles()
            );

            // Если в FileSet'е ничего не нашли, а в settings был непустой
            // массив — не затираем. Файлы могут принадлежать прототипу
            // (Block) и будут скопированы отдельно (cloneFileSetsFromModel).
            if (empty($actualFiles) && !empty($images)) {
                continue;
            }

            // Подменяем временные URL
            if (is_array($rawValue) && array_key_exists('default', $rawValue)) {
                $rawValue['default'] = $actualFiles;
                $this->setValueByPath($flatSettings, $path, $rawValue);
            } else {
                $this->setValueByPath($flatSettings, $path, $actualFiles);
            }
        }
    }

    /**
     * Клонирует store-файлы из FileSet'ов другой модели в FileSet'ы этой модели
     * и возвращает обновлённые $flatSettings с новыми URL.
     *
     * Используется, когда Block-прототип копируется в LessonBlock:
     * URL файлов прототипа продолжали бы указывать на FileSet прототипа,
     * пока мы физически не скопируем файлы в FileSet урока.
     *
     * @param  \Illuminate\Database\Eloquent\Model&\Bifurcator\FileSets\Concerns\HasFileSets  $source
     * @param  array  $flatSettings  Дерево настроек текущей модели
     * @param  string|null  $code  Код типа блока (для получения схемы)
     * @return array  Настройки с подменёнными URL
     */
    public function cloneFileSetsFromModel($source, array $flatSettings, ?string $code = null): array
    {
        if (!$code || !class_exists(\App\Blocks\Settings\BlockSettingsRegistry::class)) {
            return $flatSettings;
        }

        $schema = \App\Blocks\Settings\BlockSettingsRegistry::serializeSchema($code);
        if (empty($schema)) {
            return $flatSettings;
        }

        $imageNodes = [];
        $this->collectImageNodes($schema, $imageNodes);

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

            $rawValue = $this->getValueByPath($flatSettings, $path);
            if ($rawValue === null) {
                continue;
            }

            // Нормализуем в $images
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

            // FileSet прототипа (может отсутствовать)
            $sourceFileSet = $source->findFileSet($purposeValue);
            if (!$sourceFileSet) {
                continue;
            }

            // FileSet урока (создадим, если нет)
            $targetFileSet = $this->fileSet($purposeValue);

            // Каталоги store
            $sourceStoreDir = $sourceFileSet->storeDir();
            $targetStoreDir = $targetFileSet->storeDir();

            if (!$storeStorage->exists($targetStoreDir)) {
                $storeStorage->makeDirectory($targetStoreDir);
            }

            // Копируем физические файлы и собираем новые записи реестра
            $sourceImages = $sourceFileSet->imageList();
            $targetImages = $targetFileSet->imageList();

            // Индекс по original для дедупа
            $existingByOriginal = [];
            foreach ($targetImages as $t) {
                if (!empty($t['original'])) {
                    $existingByOriginal[$t['original']] = $t;
                }
            }

            // Индекс source по original
            $sourceByOriginal = [];
            foreach ($sourceImages as $s) {
                if (!empty($s['original'])) {
                    $sourceByOriginal[$s['original']] = $s;
                }
            }

            // Какие original'ы запрошены в settings
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
                // Если уже есть в реестре target — переиспользуем
                if (isset($existingByOriginal[$name])) {
                    $entry = $existingByOriginal[$name];
                    $entry['title'] = $incomingItem['title'] ?? ($entry['title'] ?? null);
                    $newList[] = $entry;
                    continue;
                }

                // Нет в source — файл-сирота, не копируем
                if (!isset($sourceByOriginal[$name])) {
                    continue;
                }

                $sourceEntry = $sourceByOriginal[$name];

                // Копируем оригинал
                $fromOriginal = "{$sourceStoreDir}/{$sourceEntry['original']}";
                $toOriginal   = "{$targetStoreDir}/{$sourceEntry['original']}";

                if (!$storeStorage->exists($toOriginal)) {
                    $storeStorage->copy($fromOriginal, $toOriginal);
                }

                // Копируем thumbnail (если есть)
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

            // Сохраняем реестр
            $targetFileSet->images = $newList ?: null;
            $targetFileSet->saveQuietly();

            // Формируем новые URL и пишем обратно в $flatSettings
            $newUrlItems = array_merge(
                $targetFileSet->storedImages(),
                $targetFileSet->storedPlainFiles(),
            );

            if (is_array($rawValue) && array_key_exists('default', $rawValue)) {
                $rawValue['default'] = $newUrlItems;
                $this->setValueByPath($flatSettings, $path, $rawValue);
            } else {
                $this->setValueByPath($flatSettings, $path, $newUrlItems);
            }
        }

        return $flatSettings;
    }

    /**
     * Рекурсивно собирает все узлы с type === 'image' и их пути в плоской структуре.
     */
    protected function collectImageNodes(array $nodes, array &$result, array $currentPath = []): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) continue;

            $nodeKey = $node['key'] ?? null;
            $path = $nodeKey !== null ? array_merge($currentPath, [$nodeKey]) : $currentPath;

            if (($node['type'] ?? null) === 'image') {
                $result[] = ['node' => $node, 'path' => $path];
            }

            if (!empty($node['children']) && is_array($node['children'])) {
                $this->collectImageNodes($node['children'], $result, $path);
            }
        }
    }

    /**
     * Достает значение из массива по пути.
     */
    protected function getValueByPath(array $array, array $path): mixed
    {
        $current = $array;
        foreach ($path as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }
        return $current;
    }

    /**
     * Записывает значение в массив по пути.
     */
    protected function setValueByPath(array &$array, array $path, mixed $value): void
    {
        $current = &$array;
        foreach ($path as $i => $key) {
            if ($i === count($path) - 1) {
                $current[$key] = $value;
                break;
            }
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }
    }

    /**
     * Рекурсивный поиск узла в дереве settings.
     */
    protected function findSettingNode(array $nodes, callable $predicate): ?array
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            if ($predicate($node)) {
                return $node;
            }

            if (!empty($node['children']) && is_array($node['children'])) {
                $found = $this->findSettingNode($node['children'], $predicate);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
