<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonTypeTranslation extends Model
{
    protected $fillable = [
        'lesson_type_id',
        'locale',
        'title',
        'description',
    ];

    public function lessonType(): BelongsTo
    {
        return $this->belongsTo(LessonType::class);
    }
}
