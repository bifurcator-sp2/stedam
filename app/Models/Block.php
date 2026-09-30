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
     * Название на текущей локали с фолбэком.
     */
    public function getNameAttribute(): ?string
    {
        $locale = app()->getLocale();
        $fallback = config('app.fallback_locale', 'en');

        return $this->translations->firstWhere('locale', $locale)?->name
            ?? $this->translations->firstWhere('locale', $fallback)?->name
            ?? $this->translations->first()?->name;
    }

    /**
     * Описание на текущей локали с фолбэком.
     */
    public function getDescriptionAttribute(): ?string
    {
        $locale = app()->getLocale();
        $fallback = config('app.fallback_locale', 'en');

        return $this->translations->firstWhere('locale', $locale)?->description
            ?? $this->translations->firstWhere('locale', $fallback)?->description
            ?? $this->translations->first()?->description;
    }
}
