<?php

namespace App\Models;

use App\Models\Concerns\UsesTimestamptz;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use SolutionForest\FilamentTree\Concern\ModelTree;
use Staudenmeir\LaravelAdjacencyList\Eloquent\HasRecursiveRelationships;
use Illuminate\Support\Facades\Cache;

class Taxonomy extends Model
{
    use SoftDeletes, UsesTimestamptz;

    // Разрешаем конфликт трейтов (оба имеют scopeIsRoot)
    use ModelTree, HasRecursiveRelationships {
        ModelTree::scopeIsRoot insteadof HasRecursiveRelationships;
        HasRecursiveRelationships::scopeIsRoot as scopeIsRootAdjacency;
    }

    protected $fillable = [
        'parent_id',
        'user_id',
        'order',
    ];

    protected $casts = [
        'parent_id' => 'integer',
    ];

    /**
     * Fallback: если parent_id пришёл -1, но в сессии лежит taxonomy_parent_id,
     * подставляем его. Сработает гарантированно, независимо от TreePage.
     */
    protected static function booted(): void
    {
        static::creating(function (Taxonomy $taxonomy) {
            if (($taxonomy->parent_id === null || $taxonomy->parent_id === -1)
                && session()->has('taxonomy_parent_id')
            ) {
                $taxonomy->parent_id = session()->pull('taxonomy_parent_id');
            }
        });


        // ==================== СБРОС КЭША ====================
        // При создании, изменении или удалении узла — чистим кэш
        /*static::saved(function () {
            Cache::forget('api_taxonomy_tree');
        });

        static::deleted(function () {
            Cache::forget('api_taxonomy_tree');
        });*/
    }



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

    public function lessons(): BelongsToMany
    {
        return $this->belongsToMany(Lesson::class, 'lesson_taxonomy')
            ->using(LessonTaxonomy::class)
            ->withTimestamps();
    }

    /**
     * Задания, привязанные к этой таксономии (только глобальные LessonBlock).
     */
    public function lessonBlocks(): BelongsToMany
    {
        return $this->belongsToMany(LessonBlock::class, 'lesson_block_taxonomy')
            ->using(LessonBlockTaxonomy::class)
            ->withTimestamps();
    }

    public function getTitleAttribute(): ?string
    {
        $locale = app()->getLocale();
        $fallback = config('app.fallback_locale', 'en');

        return $this->translations->firstWhere('locale', $locale)?->title
            ?? $this->translations->firstWhere('locale', $fallback)?->title
            ?? $this->translations->first()?->title;
    }

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
