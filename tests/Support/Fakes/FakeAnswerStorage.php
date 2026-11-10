<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Application\Learn\DTOs\RawAnswer;
use App\Application\Learn\Ports\AnswerStorageInterface;
use App\Domain\Learn\ValueObjects\AnswerImage;
use App\Domain\Learn\ValueObjects\LearnId;

/**
 * Фейк хранилища ответов: не трогает S3, «раскладывает» по index.
 */
final class FakeAnswerStorage implements AnswerStorageInterface
{
    /** @var array<int, list<RawAnswer>> */
    public array $stored = [];

    public function replaceAll(LearnId $learnId, array $answers): array
    {
        $this->stored[$learnId->value] = $answers;

        $images = [];
        foreach ($answers as $raw) {
            /** @var RawAnswer $raw */
            $images[] = new AnswerImage($raw->index, "answers/{$learnId->value}/{$raw->index}.webp");
        }

        return $images;
    }

    public function urlFor(AnswerImage $image): string
    {
        return 'https://cdn.test/'.$image->file;
    }
}
