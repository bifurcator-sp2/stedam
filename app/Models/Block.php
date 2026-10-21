<?php
// app/Models/Block.php

namespace App\Models;

use App\Enums\FilePurpose;
use Bifurcator\FileSets\Concerns\HasFileSets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Block extends Model
{
    use SoftDeletes, HasFileSets;

    protected $fillable = [
        'code',
        'user_id',
        'settings',
        'published',
    ];

    protected $casts = [
        'settings'  => 'array',
        'published' => 'boolean',
    ];

    /**
     * Разрешённые purpose для блоков.
     *
     * @var FilePurpose[]
     */
    protected static array $filePurposes = [
        FilePurpose::Cover,
        FilePurpose::BackgroundImage,
        FilePurpose::Gallery1,
        FilePurpose::Gallery2,
    ];

    public function lessonBlocks(): HasMany
    {
        return $this->hasMany(LessonBlock::class);
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
     * Правила для валидации на бэке и подсказок на фронте.
     */
    public static array $fileRules = [
        'image_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        'file_mimes'  => ['application/pdf', 'application/zip'],
        'max_images'  => 10,
        'max_files'   => 10,
        'max_size_kb' => 10240,
        'ratios'      => ['1x1', '1x2', '1x3', '1x4', '2x1', '3x1', '4x1'],
    ];

    /**
     * Во всех узлах дерева, где встречается этот блок.
     * Порядок — внутри каждого узла свой.
     */
    public function blockIndexes(): BelongsToMany
    {
        return $this->belongsToMany(BlockIndex::class, 'block_block_index')
            ->withPivot('order')
            ->withTimestamps()
            ->orderByPivot('order')
            ->orderBy('block_indexes.id');
    }
}
