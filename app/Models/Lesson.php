<?php

namespace App\Models;

use App\Models\Concerns\UsesTimestamptz;
use App\Traits\RequestFilterable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lesson extends Model
{
    use SoftDeletes, UsesTimestamptz;
    use RequestFilterable;

    protected $fillable = [
        'user_id',
        'locale',
        'saved',
        'title',
        'description',
        'published',
    ];

    protected $casts = [
        'saved'     => 'boolean',
        'published' => 'boolean',
    ];

    // ==================== Связи ====================

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function taxonomies(): BelongsToMany
    {
        return $this->belongsToMany(Taxonomy::class, 'lesson_taxonomy')
            ->using(LessonTaxonomy::class)
            ->withTimestamps();
    }

    /**
     * Блоки урока в порядке `order`.
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(LessonBlock::class)->orderBy('order');
    }
    /**
     * «Задания» (LessonBlock c lesson_id = 0), привязанные к уроку.
     * Один и тот же блок может быть в нескольких уроках, но в каждом — не более одного раза.
     */
    public function lessonBlockAsTests(): BelongsToMany
    {
        return $this->belongsToMany(
            LessonBlock::class,
            'lesson_lesson_block_as_test',
            'lesson_id',
            'lesson_block_id'
        )
            ->withPivot('order')
            ->withTimestamps()
            ->orderByPivot('order');
    }

    /**
     * Все блоки урока — собственные и «задания» — в едином строгом порядке.
     *
     * У собственных блоков порядок хранится в lesson_blocks."order",
     * у заданий — в pivot-колонке "order". syncBlocks пишет их в один
     * непрерывный диапазон 0..N-1, поэтому sortBy по (pivot.order ?? block.order)
     * даёт корректную склейку.
     */
    public function allBlocksOrdered(): Collection
    {
        $own = $this->relationLoaded('blocks')
            ? $this->blocks
            : $this->blocks()->get();

        $shared = $this->relationLoaded('lessonBlockAsTests')
            ? $this->lessonBlockAsTests
            : $this->lessonBlockAsTests()->get();

        return $own
            ->concat($shared)
            ->sortBy(fn (LessonBlock $b) => $b->pivot?->order ?? $b->order)
            ->values();
    }
    // ==================== Каскадное удаление ====================

    protected static function booted(): void
    {
        /**
         * Soft delete урока → soft delete всех его блоков.
         * (Если блоки уже soft-deleted — Laravel их пропустит.)
         */
        static::deleting(function (Lesson $lesson) {
            if ($lesson->isForceDeleting()) return;
            $lesson->blocks()->delete();
        });

        /**
         * Force delete урока → force delete всех его блоков.
         * Блоки, в свою очередь, через трейты:
         *  - удаляют записи FileSet
         *  - удаляют файлы из storage
         */
        static::forceDeleting(function (Lesson $lesson) {
            $lesson->blocks()->withTrashed()->forceDelete();
        });
    }


    public function isSaved(): bool
    {
        return $this->saved === true;
    }

    public function isPublished(): bool
    {
        return $this->published === true;
    }


}
