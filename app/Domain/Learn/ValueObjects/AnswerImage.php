<?php

declare(strict_types=1);

namespace App\Domain\Learn\ValueObjects;

use App\Domain\Learn\Exceptions\InvalidAnswerImage;
use JsonSerializable;

/**
 * Ссылка на изображение-ответ ученика.
 *
 * index — позиция задания (блока) среди всех блоков урока, >= 1;
 * file  — ключ объекта в хранилище (S3), относительно диска.
 *
 * Письменный ответ «пером» (whiteboard) blob в хранилище не имеет:
 * штрихи лежат в learns.answers, а file у него пустой — для учёта
 * ответа в инварианте ответ×вопрос достаточно index.
 *
 * Сам blob в домен не попадает: домен оперирует только ссылкой.
 */
final readonly class AnswerImage implements JsonSerializable
{
    /**
     * @param string|null $file ключ blob'а в хранилище; null — письменный
     *                          ответ «пером», который живёт в learns.answers
     */
    public function __construct(
        public int $index,
        public ?string $file,
    ) {
        if ($index < 1) {
            throw new InvalidAnswerImage("Answer index must be >= 1, got {$index}");
        }
        if ($file !== null && trim($file) === '') {
            throw new InvalidAnswerImage('Answer file key must not be empty');
        }
    }

    /**
     * @return array{index: int, file: string|null}
     */
    public function toArray(): array
    {
        return ['index' => $this->index, 'file' => $this->file];
    }

    /**
     * @return array{index: int, file: string|null}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function equals(self $other): bool
    {
        return $this->index === $other->index && $this->file === $other->file;
    }
}
