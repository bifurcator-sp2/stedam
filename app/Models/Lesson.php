<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lesson extends Model
{
    use SoftDeletes;

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



    /**
     * Блоки урока в порядке `order`.
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(LessonBlock::class)->orderBy('order');
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
