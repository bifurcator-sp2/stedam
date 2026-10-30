<?php

namespace App\Models;

use App\Models\Concerns\UsesTimestamptz;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonBlockTaxonomy extends Pivot
{
    use UsesTimestamptz;

    protected $table = 'lesson_block_taxonomy';

    public $incrementing = true;
    protected $primaryKey = 'id';

    protected $fillable = [
        'lesson_block_id',
        'taxonomy_id',
    ];

    /**
     * Защита на уровне модели: связывать с таксономией можно только глобальные задания.
     */
    protected static function booted(): void
    {
        static::saving(function (LessonBlockTaxonomy $pivot) {
            // Загружаем блок, чтобы проверить его lesson_id
            $block = LessonBlock::find($pivot->lesson_block_id);

            if ($block && $block->lesson_id !== null) {
                throw new \InvalidArgumentException(
                    'Связывать с таксономией можно только блоки-задания (lesson_id = null).'
                );
            }
        });
    }

    public function lessonBlock(): BelongsTo
    {
        return $this->belongsTo(LessonBlock::class);
    }

    public function taxonomy(): BelongsTo
    {
        return $this->belongsTo(Taxonomy::class);
    }
}
