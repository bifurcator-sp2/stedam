<?php
// app/Models/FileSet.php

namespace App\Models;

use App\Enums\FilePurpose;
use App\Models\Concerns\UsesTimestamptz;
use Bifurcator\FileSets\Models\FileSet as BaseFileSet;

class FileSet extends BaseFileSet
{
    use UsesTimestamptz;
    protected $casts = [
        'images'  => 'array',
        'files'   => 'array',
        'purpose' => FilePurpose::class,
    ];

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
