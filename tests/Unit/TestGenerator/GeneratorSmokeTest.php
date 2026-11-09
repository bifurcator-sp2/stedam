<?php

declare(strict_types=1);

namespace Tests\Unit\TestGenerator;

use App\Blocks\TestGenerator\Discovery\GeneratorDiscovery;
use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Random\Seed;
use App\Blocks\TestGenerator\Support\PathResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;
use Throwable;

/**
 * Сквозной smoke-тест всех генераторов из app/Blocks/TestGenerator/Generators.
 *
 * Для каждого генератора и набора сложностей (границы уровней + середины)
 * прогоняем generate() с детерминированным сидом и проверяем инварианты:
 *  - MathProblem создаётся (DTO валидирует непустые поля);
 *  - difficulty сохраняется;
 *  - topic непустой и совпадает с resolver-путём генератора;
 *  - KaTeX-разметка сбалансирована;
 *  - нет битой кодировки в тексте;
 *  - тот же сид даёт то же задание.
 */
final class GeneratorSmokeTest extends TestCase
{
    /**
     * @return list<float>
     */
    private static function difficulties(): array
    {
        return [
            0.0, 0.05, 0.19,
            0.2, 0.3, 0.39,
            0.4, 0.5, 0.59,
            0.6, 0.7, 0.79,
            0.8, 0.9, 1.0,
        ];
    }

    /**
     * @return iterable<string, array{0: string, 1: class-string, 2: float}>
     */
    public static function generatorProvider(): iterable
    {
        // ВАЖНО: data provider PHPUnit вызывается ДО setUp()/createApplication(),
        // поэтому app() здесь недоступен — сервис-контейнер ещё не поднят.
        // Строим GeneratorDiscovery напрямую из корня проекта.
        try {
            $app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
            $basePath = $app->path('Blocks/TestGenerator/Generators');

            $discovery = new GeneratorDiscovery(
                basePath: $basePath,
                baseNamespace: 'App\\Blocks\\TestGenerator\\Generators',
            );

            // discover() кэширует результат через cache() — в статическом
            // data provider это недоступно, поэтому вызываем scan() напрямую
            // через Reflection (метод private, но логика нам нужна как есть).
            $scan = new ReflectionMethod($discovery, 'scan');
            $map = $scan->invoke($discovery);
        } catch (Throwable) {
            return;
        }

        foreach ($map as $path => $class) {
            foreach (self::difficulties() as $difficulty) {
                $label = sprintf('%s @ %.2f', $path, $difficulty);
                yield $label => [$path, $class, $difficulty];
            }
        }
    }

    #[Test]
    #[DataProvider('generatorProvider')]
    public function generator_returns_valid_problem(string $path, string $class, float $difficulty): void
    {
        $this->assertTrue(class_exists($class), "Класс генератора не найден: $class");

        $generator = app($class);
        $seed = Seed::fromInt(crc32($class . ':' . $difficulty));

        $problem = $generator->generate($difficulty, $seed);

        $this->assertInstanceOf(MathProblem::class, $problem);
        $this->assertEqualsWithDelta($difficulty, $problem->difficulty, 1e-9);
        $this->assertNotSame('', trim($problem->answer), "answer пуст ($path)");
        $this->assertNotSame('', trim($problem->question), "question пуст ($path)");
        $this->assertNotSame('', trim($problem->solution), "solution пуст ($path)");

        $this->assertSame($path, $problem->topic);

        $this->assertBalancedKatex($problem->question, 'question');
        $this->assertBalancedKatex($problem->solution, 'solution');

        $this->assertNoBrokenUtf8($problem->question, 'question');
        $this->assertNoBrokenUtf8($problem->solution, 'solution');
    }

    #[Test]
    public function same_seed_gives_same_problem(): void
    {
        $map = app(PathResolver::class)->all();
        $this->assertNotEmpty($map, 'Не найдено ни одного генератора');

        foreach ($map as $path => $class) {
            $generator = app($class);

            // Один и тот же int-сид → один и тот же Randomizer → одно задание.
            // Передаём свежий Seed::fromInt() на каждый вызов: сам Randomizer
            // мутабелен и не клонируется (Random\Randomizer::__clone запрещён).
            $seed = crc32('determinism:' . $path);

            $a = $generator->generate(0.5, Seed::fromInt($seed));
            $b = $generator->generate(0.5, Seed::fromInt($seed));

            $this->assertSame($a->toArray(), $b->toArray(), "Недетерминирован: $path");
        }
    }

    #[Test]
    public function path_resolver_round_trip(): void
    {
        foreach (app(PathResolver::class)->all() as $path => $class) {
            $this->assertSame($class, app(PathResolver::class)->find($path));
            $this->assertSame($path, app(PathResolver::class)->pathFor($class));
        }
    }

    /**
     * UTF-8 сломанная кириллица превращается в «Р» + мусорный символ
     * (например, «Р\u{98}», «Р\u{A0}»). Одиночная «Р» в нормальном тексте
     * возможна (слово с заглавной «Р», «Решение»), поэтому ловим именно
     * пару «Р» + спецсимвол в диапазоне 0x80–0x9F либо NBSP.
     */
    private function assertNoBrokenUtf8(string $text, string $field): void
    {
        $result = preg_match('/Р[\x{80}-\x{9F}\x{A0}]/u', $text, $m);

        if ($result === 1) {
            $this->fail("Битая кодировка в $field: найден фрагмент «" . $m[0] . '»');
        }

        $this->assertSame(0, $result, "Ошибка preg_match при проверке кодировки в $field");
    }

    private function assertBalancedKatex(string $text, string $field): void
    {
        $inlineOpen = substr_count($text, '\(');
        $inlineClose = substr_count($text, '\)');
        $displayOpen = substr_count($text, '\[');
        $displayClose = substr_count($text, '\]');

        $this->assertSame($inlineOpen, $inlineClose, "Несбалансированы inline-делимитеры в $field");
        $this->assertSame($displayOpen, $displayClose, "Несбалансированы display-делимитеры в $field");
    }
}
