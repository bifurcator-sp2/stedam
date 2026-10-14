<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use SolutionForest\FilamentTree\Concern\ModelTree;

class Taxonomy extends Model
{
    use SoftDeletes;
    use ModelTree;

    protected $fillable = [
        'parent_id',
        'user_id',
        'order',
    ];

    protected $casts = [
        'parent_id' => 'integer', // <-- полезно для плагина
    ];

    public function translations(): HasMany
    {
        return $this->hasMany(TaxonomyTranslation::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Taxonomy::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Taxonomy::class, 'parent_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Заголовок на текущей локали с фолбэком.
     */
    public function getTitleAttribute(): ?string
    {
        $locale = app()->getLocale();
        $fallback = config('app.fallback_locale', 'en');

        return $this->translations->firstWhere('locale', $locale)?->title
            ?? $this->translations->firstWhere('locale', $fallback)?->title
            ?? $this->translations->first()?->title;
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

    public function isRoot(): bool
    {
        return (int) $this->parent_id === -1;
    }
}
