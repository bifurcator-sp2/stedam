<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockTranslation extends Model
{
    protected $fillable = [
        'block_id',
        'locale',
        'name',
        'description',
    ];

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }
}
