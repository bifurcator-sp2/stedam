<?php
// app/Models/LessonBlock.php

namespace App\Models;

use App\Enums\FilePurpose;
use App\Models\Concerns\UsesTimestamptz;
use Bifurcator\FileSets\Concerns\HasFileSets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LessonBlock extends Model
{
    use SoftDeletes, HasFileSets, UsesTimestamptz;

    protected $fillable = [
        'user_id',
        'lesson_id',
        'block_id',
        'order',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
        'order'    => 'integer',
    ];

    protected static array $filePurposes = [
        FilePurpose::Cover,
        FilePurpose::BackgroundImage,
        FilePurpose::Gallery1,
        FilePurpose::Gallery2,
    ];

    public static array $fileRules = [
        'image_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        'file_mimes'  => ['application/pdf', 'application/zip'],
        'max_images'  => 10,
        'max_files'   => 10,
        'max_size_kb' => 10240,
        'ratios'      => ['1x1', '1x2', '1x3', '1x4', '2x1', '3x1', '4x1'],
    ];

    // ==================== Связи ====================

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Таксономии задания (доступно только для глобальных заданий, где lesson_id = null).
     */
    public function taxonomies(): BelongsToMany
    {
        return $this->belongsToMany(Taxonomy::class, 'lesson_block_taxonomy')
            ->using(LessonBlockTaxonomy::class)
            ->withTimestamps();
    }

    /**
     * Уроки, в которых данное задание используется.
     */
    public function usages(): BelongsToMany
    {
        return $this->belongsToMany(
            Lesson::class,
            'lesson_lesson_block_as_test',
            'lesson_block_id',
            'lesson_id'
        )->withPivot('order');
    }

    // ==================== Автозаполнение ====================

    protected static function booted(): void
    {
        static::creating(function (LessonBlock $block) {
            if (!$block->user_id && $block->lesson_id) {
                $block->user_id = Lesson::query()
                    ->whereKey($block->lesson_id)
                    ->value('user_id');
            }
        });
    }
}
