<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use SolutionForest\FilamentTree\Concern\ModelTree;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BlockIndex extends Model
{
    use SoftDeletes;
    use ModelTree;

    // Явно указываем таблицу, чтобы Laravel не искал block_indices
    protected $table = 'block_indexes';

    protected $fillable = [
        'parent_id',
        'user_id',
        'order',
    ];

    protected $casts = [
        'parent_id' => 'integer',
    ];

    /**
     * Fallback: если parent_id пришёл -1, но в сессии лежит block_index_parent_id,
     * подставляем его. Сработает гарантированно, независимо от TreePage.
     */
    protected static function booted(): void
    {
        static::creating(function (BlockIndex $blockIndex) {
            if (($blockIndex->parent_id === null || $blockIndex->parent_id === -1)
                && session()->has('block_index_parent_id')
            ) {
                $blockIndex->parent_id = session()->pull('block_index_parent_id');
            }
        });
    }

    public function blockPivots(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BlockBlockIndex::class, 'block_index_id')->orderBy('order');
    }
    public function translations(): HasMany
    {
        return $this->hasMany(BlockIndexTranslation::class, 'block_index_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(BlockIndex::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(BlockIndex::class, 'parent_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
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

    public function blocks(): BelongsToMany
    {
        return $this->belongsToMany(Block::class, 'block_block_index')
            ->withPivot('order')
            ->withTimestamps()
            ->orderByPivot('order')
            ->orderBy('blocks.id');
    }
}
