<?php
// app/Traits/HasFileSets.php

namespace App\Traits;

use App\Enums\FilePurpose;
use App\Models\FileSet;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasFileSets
{
    /**
     * Все наборы файлов, привязанные к модели.
     */
    public function fileSets(): MorphMany
    {
        return $this->morphMany(FileSet::class, 'owner', 'model', 'model_id');
    }

    /**
     * Список разрешённых purpose для модели.
     *
     * Если в модели объявлено свойство $filePurposes — используем его.
     * Иначе — все значения FilePurpose.
     *
     * @return FilePurpose[]|string[]
     */
    public static function filePurposes(): array
    {
        if (property_exists(static::class, 'filePurposes')) {
            return static::$filePurposes;
        }

        return FilePurpose::cases();
    }

    /**
     * Разрешён ли purpose для этой модели.
     */
    public static function allowsFilePurpose(FilePurpose|string $purpose): bool
    {
        $value = $purpose instanceof FilePurpose ? $purpose->value : $purpose;

        foreach (static::filePurposes() as $allowed) {
            $allowedValue = $allowed instanceof FilePurpose ? $allowed->value : $allowed;

            if ($allowedValue === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Получить (или создать) набор файлов указанного назначения.
     */
    public function fileSet(FilePurpose|string $purpose): FileSet
    {
        if (!static::allowsFilePurpose($purpose)) {
            throw new \InvalidArgumentException(
                'Модель ' . static::class . ' не поддерживает purpose: ' . $purpose
            );
        }

        $value = $purpose instanceof FilePurpose ? $purpose->value : $purpose;

        return FileSet::firstOrCreate([
            'model'    => static::class,
            'model_id' => $this->getKey(),
            'purpose'  => $value,
        ]);
    }

    /**
     * Найти существующий FileSet без создания.
     */
    public function findFileSet(FilePurpose|string $purpose): ?FileSet
    {
        $value = $purpose instanceof FilePurpose ? $purpose->value : $purpose;

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
