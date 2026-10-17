<?php
// packages/bifurcator/laravel-file-sets/src/Models/FileSet.php

namespace Bifurcator\FileSets\Models;

use Bifurcator\FileSets\Concerns\FilesContainer;
use Bifurcator\FileSets\Contracts\FileSetContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FileSet extends Model implements FileSetContract
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
        'images' => 'array',
        'files'  => 'array',
    ];

    /**
     * Полиморфная связь с владельцем.
     */
    public function owner(): MorphTo
    {
        return $this->morphTo(null, 'model', 'model_id');
    }
}
