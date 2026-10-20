<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class BlockBlockIndex extends Pivot
{
    protected $table = 'block_block_index';

    public $incrementing = true; // Важно для Filament
    protected $fillable = ['block_index_id', 'block_id', 'order'];

    public function blockIndex(): BelongsTo
    {
        return $this->belongsTo(BlockIndex::class);
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }
}
