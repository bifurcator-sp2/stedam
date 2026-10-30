<?php


namespace App\Models;

use App\Models\Concerns\UsesTimestamptz;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonTaxonomy extends Pivot
{
    use UsesTimestamptz;

    protected $table = 'lesson_taxonomy';

    // Указываем, что в таблице есть автоинкрементный id (как на скрине)
    public $incrementing = true;
    protected $primaryKey = 'id';

    protected $fillable = [
        'lesson_id',
        'taxonomy_id',
    ];

    // Опционально: связи для удобства (если понадобятся)
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function taxonomy(): BelongsTo
    {
        return $this->belongsTo(Taxonomy::class);
    }
}
