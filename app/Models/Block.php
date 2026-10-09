<?php

namespace App\Models;

use App\Traits\FilesContainer;
use App\Traits\HasFileSets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Enums\FilePurpose;

class Block extends Model
{
    use SoftDeletes, FilesContainer, HasFileSets;

    protected $fillable = [
        'block_type_id',
        'user_id',
        'settings',
        'images',
        'files',
    ];

    protected $casts = [
        'settings' => 'array',
        'images' => 'array',
        'files'  => 'array',
    ];

    /**
     * Разрешённые purpose для блоков.
     *
     * @var FilePurpose[]
     */
    protected static array $filePurposes = [
        FilePurpose::Cover,
        FilePurpose::BackgroundImage,
    ];

    public function blockType(): BelongsTo
    {
        return $this->belongsTo(BlockType::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(BlockTranslation::class);
    }

    /**
     * Хелперы для удобства: получить перевод на текущей локали с фолбэком.
     * Используются в BlockResource.
     */
    public function translationFor(?string $locale = null): ?BlockTranslation
    {
        $locale ??= app()->getLocale();
        $fallback = config('app.fallback_locale', 'en');

        return $this->translations->firstWhere('locale', $locale)
            ?? $this->translations->firstWhere('locale', $fallback)
            ?? $this->translations->first();
    }

    public function getTitleAttribute(): ?string
    {
        return $this->translationFor()?->title;
    }

    public function getDescriptionAttribute(): ?string
    {
        return $this->translationFor()?->description;
    }

    /**
     * Процессоры, применяемые к перенесённым файлам.
     * Каждый класс должен реализовать метод handle($model, array $filenames): void
     */
    protected array $fileProcessors = [
        \App\Files\Processors\ThumbnailProcessor::class,
    ];

    /**
     * Правила для валидации на бэке и подсказок на фронте.
     */
    public static array $fileRules = [
        'image_mimes'    => ['image/jpeg', 'image/png', 'image/webp'],
        'file_mimes'     => ['application/pdf', 'application/zip'],
        'max_images'     => 10,
        'max_files'      => 10,
        'max_size_kb'    => 10240,
        'ratios'         => ['1x1', '1x2', '1x3', '1x4', '2x1', '3x1', '4x1'],
    ];

}
