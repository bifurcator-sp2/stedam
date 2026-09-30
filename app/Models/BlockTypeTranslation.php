<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockTypeTranslation extends Model
{
    protected $fillable = [
        'block_type_id',
        'locale',
        'name',
        'description',
    ];

    public function blockType(): BelongsTo
    {
        return $this->belongsTo(BlockType::class);
    }
}
