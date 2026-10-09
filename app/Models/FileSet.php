<?php
// app/Models/FileSet.php

namespace App\Models;

use App\Enums\FilePurpose;
use App\Traits\FilesContainer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FileSet extends Model
{
    use SoftDeletes, FilesContainer;

    protected $fillable = [
        'model',
        'model_id',
        'purpose',
        'images',
        'files',
    ];

    protected $casts = [
        'images'  => 'array',
        'files'   => 'array',
        'purpose' => FilePurpose::class,   // ← авто-каст в enum
    ];

    /**
     * Полиморфная связь с владельцем.
     */
    public function owner(): MorphTo
    {
        return $this->morphTo(null, 'model', 'model_id');
    }

    /**
     * Процессоры.
     */
    protected array $fileProcessors = [
        \App\Files\Processors\ThumbnailProcessor::class,
    ];

    /**
     * Правила.
     */
    public static array $fileRules = [
        'image_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        'file_mimes'  => ['application/pdf', 'application/zip'],
        'max_size_kb' => 10240,
        'ratios'      => ['1x1', '1x2', '1x3', '1x4', '2x1', '3x1', '4x1'],
    ];
}
