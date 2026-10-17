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

        foreach (static::filePurposes() as $purpose) {
            $value = $purpose instanceof \BackedEnum ? $purpose->value : $purpose;

            $found = $this->findSettingNodeWithPath(
                $schema,
                function (array $n) use ($value): bool {
                    if (($n['type'] ?? null) !== 'image') return false;

                    $nodePurpose = $n['params']['purpose'] ?? null;
                    if ($nodePurpose instanceof \BackedEnum) {
                        $nodePurpose = $nodePurpose->value;
                    }

                    if ($nodePurpose !== null) {
                        return $nodePurpose === $value;
                    }

                    $allowed = $n['allowed'] ?? null;
                    if (is_array($allowed) && isset($allowed[0])) {
                        $allowedPurpose = $allowed[0];
                        if ($allowedPurpose instanceof \BackedEnum) {
                            $allowedPurpose = $allowedPurpose->value;
                        }
                        return $allowedPurpose === $value;
                    }

                    return false;
                }
            );

            if ($found === null) {
                continue;
            }

            $path = $found['path'];
            $rawValue = $this->getValueByPath($flatSettings, $path);

            // ========================================================
            // ИЗВЛЕЧЕНИЕ картинок из плоских настроек
            // ========================================================
            $images = [];
            if (is_array($rawValue)) {
                if (array_key_exists('default', $rawValue) && is_array($rawValue['default'])) {
                    $images = $rawValue['default'];
                } else {
                    $images = $rawValue;
                }
            }

            // 1. Получаем целевой FileSet для текущего блока
            $fileSet = $this->fileSet($value);

            // 2. Переносим файлы из FileSet'а временной модели (model_id = 0)
            // Это нужно при создании нового блока, так как картинки загрузились в model_id=0
            $class = static::fileSetModel();
            $stubFileSet = $class::where([
                'model'    => static::class,
                'model_id' => 0,
                'purpose'  => $value,
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
                    // После переноса удаляем пустую папку временного FileSet
                    $tempStorage->deleteDirectory($fromDir);
                }
            }

            // 3. Синхронизируем (переносит temp -> store и обновляет БД)
            $fileSet->syncFilesFromRequest($images, null);

            // 4. Забираем актуальные файлы (уже с постоянными URL)
            $actualFiles = array_merge(
                $fileSet->storedImages(),
                $fileSet->storedPlainFiles()
            );

            // 5. Подменяем временные URL в переданных настройках
            if (is_array($rawValue) && array_key_exists('default', $rawValue)) {
                $rawValue['default'] = $actualFiles;
                $this->setValueByPath($flatSettings, $path, $rawValue);
            } else {
                $this->setValueByPath($flatSettings, $path, $actualFiles);
            }
        }
    }

    /**
     * Рекурсивный поиск узла в дереве схемы с запоминанием пути.
     */
    protected function findSettingNodeWithPath(array $nodes, callable $predicate, array $currentPath = []): ?array
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) continue;

            $nodeKey = $node['key'] ?? null;
            $path = $nodeKey !== null ? array_merge($currentPath, [$nodeKey]) : $currentPath;

            if ($predicate($node)) {
                return ['node' => $node, 'path' => $path];
            }

            if (!empty($node['children']) && is_array($node['children'])) {
                $found = $this->findSettingNodeWithPath($node['children'], $predicate, $path);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
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
