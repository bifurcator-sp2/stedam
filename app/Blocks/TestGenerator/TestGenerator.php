<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator;

use App\Blocks\TestGenerator\Contracts\MathProblemGeneratorInterface;
use App\Blocks\TestGenerator\DTO\MathProblem;
use InvalidArgumentException;

/**
 * Оркестратор генерации математических заданий.
 *
 * Хранит реестр стратегий (MathProblemGeneratorInterface), индексированный
 * по topic(), и делегирует генерацию нужной стратегии.
 *
 * Источник экземпляров — TestGeneratorServiceProvider, который собирает
 * их через GeneratorDiscovery (скан папки Generators/...). Если провайдер
 * не зарегистрирован, контейнер выбросит BindingResolutionException на
 * iterable $generators — это правильно: провайдер обязателен.
 *
 * Чтобы добавить новый генератор, достаточно положить класс, реализующий
 * MathProblemGeneratorInterface, в app/Blocks/TestGenerator/Generators/...
 * (путь = resolver-сегменты через точку) и сбросить кеш discovery.
 */
final class TestGenerator
{
    /** @var array<string, MathProblemGeneratorInterface> */
    private array $generators = [];

    /**
     * @param iterable<MathProblemGeneratorInterface> $generators
     *
     * @throws InvalidArgumentException Если два генератора объявили одинаковый topic().
     */
    public function __construct(iterable $generators)
    {
        foreach ($generators as $generator) {
            $topic = self::normalizeTopic($generator::topic());

            if (isset($this->generators[$topic])) {
                throw new InvalidArgumentException(
                    sprintf('Duplicate generator for topic "%s".', $topic)
                );
            }

            $this->generators[$topic] = $generator;
        }
    }

    /**
     * Сгенерировать задание по теме и сложности.
     *
     * @param string $topic      Ключ темы (регистронезависимый, обрезается по краям).
     * @param float  $difficulty Сложность от 0.0 (простое) до 1.0 (олимпиадное).
     *
     * @throws InvalidArgumentException Если сложность вне [0.0, 1.0] или тема не поддерживается.
     */
    public function generate(string $topic, float $difficulty): MathProblem
    {
        if ($difficulty < 0.0 || $difficulty > 1.0) {
            throw new InvalidArgumentException(
                sprintf('Difficulty must be between 0 and 1, %f given.', $difficulty)
            );
        }

        $topic = self::normalizeTopic($topic);

        if (! isset($this->generators[$topic])) {
            throw new InvalidArgumentException(
                sprintf(
                    'No generator registered for topic "%s". Available: %s.',
                    $topic,
                    $this->availableTopics() === []
                        ? '<none>'
                        : implode(', ', $this->availableTopics()),
                )
            );
        }

        return $this->generators[$topic]->generate($difficulty);
    }

    /**
     * Поддерживается ли тема.
     */
    public function supports(string $topic): bool
    {
        return isset($this->generators[self::normalizeTopic($topic)]);
    }

    /**
     * Список зарегистрированных тем.
     *
     * @return string[]
     */
    public function availableTopics(): array
    {
        return array_keys($this->generators);
    }

    private static function normalizeTopic(string $topic): string
    {
        return strtolower(trim($topic));
    }
}
