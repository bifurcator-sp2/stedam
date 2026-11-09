<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators;

use App\Blocks\TestGenerator\Contracts\MathProblemGeneratorInterface;
use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Support\KaTeX;
use App\Blocks\TestGenerator\Support\PathResolver;
use InvalidArgumentException;
use RuntimeException;

/**
 * РвЂР°Р·РѕРІС‹Р№ Р°Р±СЃС‚СЂР°РєС‚РЅС‹Р№ РєР»Р°СЃСЃ РґР»СЏ MathProblemGeneratorInterface.
 *
 * Р’Р°Р»РёРґРёСЂСѓРµС‚ difficulty Рё РїРµСЂРµРґР°СвЂС‚ РІ build(). РўР°РєР¶Рµ РїСЂРµРґРѕСЃС‚Р°РІР»СЏРµС‚
 * path() вЂ” resolver-РїСѓС‚СЊ РіРµРЅРµСЂР°С‚РѕСЂР°, РІС‹С‡РёСЃР»СЏРµРСС‹Р№ РёР· РёРСРµРЅРё/СЂР°СЃРїРѕР»РѕР¶РµРЅРёСЏ РєР»Р°СЃСЃР°.
 */
abstract class GeneratorBase implements MathProblemGeneratorInterface
{
    /** Кэш вычисленного resolver-пути. */
    private ?string $path = null;

    final public function generate(float $difficulty): MathProblem
    {
        if ($difficulty < 0.0 || $difficulty > 1.0) {
            throw new InvalidArgumentException(
                sprintf('Difficulty must be between 0 and 1, %f given.', $difficulty)
            );
        }
        return $this->build($difficulty);
    }

    abstract protected function build(float $difficulty): MathProblem;

    /**
     * Resolver-РїСѓС‚СЊ С‚РµРєСѓС‰РµРіРѕ РіРµРЅРµСЂР°С‚РѕСЂР°: 'math.algebra.equations.linearEquation'.
     * Р’С‹С‡РёСЃР»СЏРµС‚СЃСЏ РѕРґРёРЅ СЂР°Р· Рё РєСЌС€РёСЂСѓРµС‚СЃСЏ РЅР° РёРЅСЃС‚Р°РЅСЃ.
     */
    protected function path(): string
    {
        if ($this->path === null) {
            $class = static::class;

            $resolved = app(PathResolver::class)->pathFor($class);

            if ($resolved === null) {
                throw new RuntimeException(sprintf(
                    'No resolver path found for generator class "%s". '
                    . 'Make sure it lives under Generators/ and implements the interface.',
                    $class,
                ));
            }

            $this->path = $resolved;
        }

        return $this->path;
    }

    /** РЎР»СѓС‡Р°Р№РЅРѕРµ С†РµР»РѕРµ [min, max], РІРєР»СЋС‡РёС‚РµР»СЊРЅРѕ СЃ РѕР±РµРёС… СЃС‚РѕСЂРѕРЅ. */
    protected function rnd(int $min, int $max): int
    {
        return random_int($min, max($min, $max));
    }

    /** @template T @param list<T> $options @return T */
    protected function pick(array $options): mixed
    {
        return $options[array_rand($options)];
    }

    /** Р¤РѕСЂРСР°С‚РёСЂРѕРІР°РЅРёРµ С‡РёСЃР»Р° РґР»СЏ KaTeX (Р±РµР· Р»РёС€РЅРёС… РЅСѓР»РµР№). */
    protected function num(int|float $value, int $precision = 2): string
    {
        return KaTeX::number($value, $precision);
    }
}