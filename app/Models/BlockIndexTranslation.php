<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockIndexTranslation extends Model
{
    protected $fillable = [
        'block_index_id',
        'locale',
        'title',
        'description',
    ];

    public function blockIndex(): BelongsTo
    {
        return $this->belongsTo(BlockIndex::class);
    }
}
