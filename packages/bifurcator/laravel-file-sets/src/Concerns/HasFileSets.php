<?php
// packages/bifurcator/laravel-file-sets/src/Concerns/HasFileSets.php

namespace Bifurcator\FileSets\Concerns;

use Bifurcator\FileSets\Contracts\FileSetContract;
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
}
