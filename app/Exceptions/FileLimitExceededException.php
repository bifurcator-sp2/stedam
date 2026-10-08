<?php
// app/Exceptions/FileLimitExceededException.php

namespace App\Exceptions;

use RuntimeException;

class FileLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $limitType, // 'images' | 'files'
        public readonly int $max,
        public readonly int $attempted,
    ) {
        $label = $limitType === 'images' ? 'изображений' : 'файлов';

        parent::__construct(
            "Превышен лимит {$label}: максимум {$max}, попытка сохранить {$attempted}."
        );
    }

    public function render($request)
{
    return response()->json([
        'message'    => $this->getMessage(),
        'limit_type' => $this->limitType,
        'max'        => $this->max,
        'attempted'  => $this->attempted,
    ], 422);
}
}
