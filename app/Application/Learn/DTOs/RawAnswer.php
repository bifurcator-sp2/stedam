<?php

declare(strict_types=1);

namespace App\Application\Learn\DTOs;

use Illuminate\Http\UploadedFile;

/**
 * Сырой ответ ученика до сохранения: позиция блока + загруженный файл-картинка.
 */
final readonly class RawAnswer
{
    public function __construct(
        public int $index,
        public UploadedFile $image,
    ) {}
}
