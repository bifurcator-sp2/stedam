<?php

declare(strict_types=1);

namespace App\Domain\Learn\ValueObjects;

use App\Domain\Learn\Exceptions\InvalidAnswerImage;
use JsonSerializable;

/**
 * Ответы ученика на задания урока — список изображений-ответов.
 *
 * Каждый элемент: { index (позиция блока, >=1), file (ключ в S3) }.
 * Для ответа «пером» file = null: штрихи лежат в learns.answers.
 * Неизменяемый value object.
 *
 * Инварианты:
 *   - индексы уникальны;
 *   - список упорядочен по index возрастанию.
 *
 * Семантика при обновлении — полная замена (автосохранение), см. Learn::updateAnswers().
 */
final readonly class LearnAnswers implements JsonSerializable
{
    /** @var list<AnswerImage> */
    private array $items;

    /**
     * @param list<AnswerImage> $items
     */
    private function __construct(array $items)
    {
        $seen = [];
        foreach ($items as $item) {
            if (isset($seen[$item->index])) {
                throw new InvalidAnswerImage("Duplicate answer index: {$item->index}");
            }
            $seen[$item->index] = true;
        }

        usort($items, static fn (AnswerImage $a, AnswerImage $b): int => $a->index <=> $b->index);
        $this->items = $items;
    }

    /**
     * @param list<AnswerImage> $items
     */
    public static function fromImages(array $items): self
    {
        return new self($items);
    }

    /**
     * Восстановление из персистентного представления (список {index,file}).
     *
     * Ответ «пером» (whiteboard) — это блок штрихов с полем index (позиция
     * задания, 1-based) и без file: он восстанавливается с file = null.
     * Блоки без index (старые записи, ответы клиента без позиции) в домен
     * не попадают — позицию выполненного задания восстановить нельзя.
     *
     * @param array<int, array{index?: mixed, file?: mixed}> $raw
     */
    public static function fromArray(array $raw): self
    {
        $items = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || !isset($entry['index'])) {
                // Чужие/старые данные считаем «нет ответа».
                continue;
            }
            $index = (int) $entry['index'];
            if ($index < 1) {
                // §7.2: элементы чужой/старой структуры (index < 1, нечисловой)
                // игнорируются, а не роняют гидратацию.
                continue;
            }
            $file = isset($entry['file']) ? (string) $entry['file'] : null;
            $items[] = new AnswerImage($index, $file);
        }

        return new self($items);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @return list<array{index: int, file: string}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (AnswerImage $i): array => $i->toArray(),
            $this->items,
        );
    }

    /**
     * @return list<array{index: int, file: string}>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return list<AnswerImage>
     */
    public function images(): array
    {
        return $this->items;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function equals(self $other): bool
    {
        return $this->toArray() === $other->toArray();
    }
}

