<?php

declare(strict_types=1);

namespace App\Domain\Learn\ValueObjects;

use App\Domain\Learn\Exceptions\InvalidQuestion;
use JsonSerializable;

/**
 * Упорядоченный набор сгенерированных заданий прохождения.
 *
 * Содержит ТОЛЬКО генеративные блоки: у статических блоков содержимое
 * запечено в снапшоте урока и одинаково для всех прохождений.
 *
 * Инварианты:
 *   - индексы уникальны (иначе InvalidQuestion);
 *   - список упорядочен по index возрастанию.
 *
 * Связь с LearnAnswers — по совпадению index: каждый answers[i].index
 * должен иметь questions[k].index с тем же значением (обратное не требуется).
 *
 * См. спеку §7.8 / §8.1.
 */
final readonly class LearnQuestions implements JsonSerializable
{
    /** @var list<QuestionItem> */
    private array $items;

    /**
     * @param list<QuestionItem> $items
     */
    private function __construct(array $items)
    {
        $seen = [];
        foreach ($items as $item) {
            if (isset($seen[$item->index])) {
                throw new InvalidQuestion("Duplicate question index: {$item->index}");
            }
            $seen[$item->index] = true;
        }

        usort($items, static fn (QuestionItem $a, QuestionItem $b): int => $a->index <=> $b->index);
        $this->items = $items;
    }

    /**
     * @param list<QuestionItem> $items
     */
    public static function fromItems(array $items): self
    {
        return new self($items);
    }

    /**
     * Восстановление из персистентного представления (список массивов).
     * Битые/чужие элементы молча пропускаются — гидратация не должна падать
     * на исторических данных.
     *
     * @param array<int, array<string, mixed>> $raw
     */
    public static function fromArray(array $raw): self
    {
        $items = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || !isset($entry['index'], $entry['question'])) {
                continue;
            }

            $index = (int) $entry['index'];
            if ($index < 1) {
                continue;
            }

            $question = (string) $entry['question'];
            if (trim($question) === '') {
                continue;
            }

            $difficulty = isset($entry['difficulty']) ? (float) $entry['difficulty'] : 0.5;
            if ($difficulty < 0.0) {
                $difficulty = 0.0;
            } elseif ($difficulty > 1.0) {
                $difficulty = 1.0;
            }

            $items[] = new QuestionItem(
                index:      $index,
                question:   $question,
                answer:     (string) ($entry['answer'] ?? ''),
                solution:   (string) ($entry['solution'] ?? ''),
                topic:      (string) ($entry['topic'] ?? ''),
                difficulty: $difficulty,
                seed:       isset($entry['seed']) ? (int) $entry['seed'] : null,
            );
        }

        return new self($items);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @return list<QuestionItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Набор индексов (для быстрой проверки answers ⊆ questions).
     *
     * @return list<int>
     */
    public function indices(): array
    {
        return array_map(
            static fn (QuestionItem $i): int => $i->index,
            $this->items,
        );
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (QuestionItem $i): array => $i->toArray(),
            $this->items,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function equals(self $other): bool
    {
        return $this->toArray() === $other->toArray();
    }
}
