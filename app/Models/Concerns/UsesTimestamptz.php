<?php

namespace App\Models\Concerns;

trait UsesTimestamptz
{
    /**
     * Формат сериализации для timestamptz-колонок.
     * 'P' добавляет явное смещение (+03:00), чтобы PG
     * сохранил правильный UTC-момент, а не угадывал TZ.
     */
    protected $dateFormat = 'Y-m-d H:i:s P';
}
