<?php

namespace App\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;

abstract class TimestamptzPivot extends Pivot
{
    protected $dateFormat = 'Y-m-d H:i:s P';
}
