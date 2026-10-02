<?php

namespace App\Models;

use App\Blocks\Settings\SettingsSynchronizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BlockType extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'type',
        'default_settings',
        'user_id',
    ];

    protected $casts = [
        'default_settings' => 'array',
    ];

    public function translations(): HasMany
    {
        return $this->hasMany(BlockTypeTranslation::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
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
            ?? $this->translations->first()?->name
            ?? $this->code;
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

    public function getNormalizedSettingsAttribute(): ?array
    {
        return SettingsSynchronizer::forForm(
            $this['code'] ?? null,
            $this['default_settings'] ?? [],
        );
    }



    public function isInfo(): bool
    {
        return $this->type === 'info';
    }

    public function isTask(): bool
    {
        return $this->type === 'task';
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class);
    }
}
