<?php
// packages/bifurcator/laravel-file-sets/src/Exceptions/FileLimitExceededException.php

namespace Bifurcator\FileSets\Exceptions;

use RuntimeException;

/**
 * Исключение: превышен лимит файлов или изображений.
 *
 * Бросается в FilesContainer при попытке сохранить больше файлов,
 * чем разрешено конфигом или $fileRules модели.
 */
class FileLimitExceededException extends RuntimeException
{
    public function __construct(
        protected string $kind,
        protected int $max,
        protected int $actual,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        $message = $message ?: sprintf(
            'Превышен лимит %s: разрешено %d, получено %d.',
            $kind,
            $max,
            $actual,
        );

        parent::__construct($message, $code, $previous);
    }

    /**
     * 'images' или 'files'.
     */
    public function getKind(): string
    {
        return $this->kind;
    }

    public function getMax(): int
    {
        return $this->max;
    }

    public function getActual(): int
    {
        return $this->actual;
    }
}
