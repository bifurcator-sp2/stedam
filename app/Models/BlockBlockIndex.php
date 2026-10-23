<?php
// app/Models/BlockBlockIndex.php

namespace App\Models;

use App\Models\Pivots\TimestamptzPivot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class BlockBlockIndex extends TimestamptzPivot
{
    protected $table = 'block_block_index';

    public $incrementing = true;

    protected $fillable = ['block_index_id', 'block_id', 'order'];

    // Добавляем касты для порядка, чтобы Filament корректно с ним работал
    protected $casts = [
        'order' => 'integer',
    ];

    public function blockIndex(): BelongsTo
    {
        return $this->belongsTo(BlockIndex::class);
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }
}
