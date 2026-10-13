<?php

namespace App\Models;

use App\Enums\FilePurpose;
use App\Traits\FilesContainer;
use App\Traits\HasFileSets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LessonBlock extends Model
{
    use SoftDeletes, FilesContainer, HasFileSets;

    protected $fillable = [
        'user_id',
        'lesson_id',
        'block_id',
        'order',
        'settings',
        'files',
        'images',
    ];

    protected $casts = [
        'settings' => 'array',
        'files'    => 'array',
        'images'   => 'array',
        'order'    => 'integer',
    ];

    protected static array $filePurposes = [
        FilePurpose::Cover,
        FilePurpose::BackgroundImage,
    ];

    protected array $fileProcessors = [
        \App\Files\Processors\ThumbnailProcessor::class,
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
