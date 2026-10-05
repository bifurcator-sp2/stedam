<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Block extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'block_type_id',
        'user_id',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
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
}
