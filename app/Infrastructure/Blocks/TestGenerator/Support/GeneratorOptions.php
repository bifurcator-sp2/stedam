<?php

declare(strict_types=1);

namespace App\Infrastructure\Blocks\TestGenerator\Support;

use App\Infrastructure\Blocks\TestGenerator\Discovery\GeneratorDiscovery;

/**
 * Список зарегистрированных генераторов для Filament Select.
 *
 * Источник — GeneratorDiscovery (скан папки Generators). Ключ — resolver-путь
 * ('math.algebra'), значение — отображаемое имя ('Math.Algebra').
 *
 * Один и тот же источник правды, что и у TestResolverMap: то, что реально
 * загружено в приложение, а не ручной список в конфиге.
 *
 * Использование в Filament:
 *   Select::make('test_resolver')
 *       ->options(fn () => app(GeneratorOptions::class)->grouped());
 *
 * grouped() отдаёт вложенный массив, который Filament автоматически
 * интерпретирует как optgroup — «Math» → [Algebra, Arithmetic, Geometry].
 */
final class GeneratorOptions
{
    public function __construct(
        private readonly GeneratorDiscovery $discovery,
    ) {
    }

/**
 * Сгруппированные опции для Filament Select:
 *   [
 *     'Math' => [
 *       'math.algebra'    => 'Algebra',
 *       'math.arithmetic' => 'Arithmetic',
 *       'math.geometry'   => 'Geometry',
 *     ],
 *     'Physics' => [
 *       'physics.mechanics' => 'Mechanics',
 *     ],
 *   ]
 *
 * Внутри группы — только сегменты ПОСЛЕ первого, потому что первая
 * уже стала заголовком группы. Так «Math.Algebra» читается лучше,
 * чем дублирование «Math» в каждой опции.
 *
 * @return array<string, array<string, string>>
 */
public function grouped(): array
{
    $result = [];

    foreach ($this->discovery->discover() as $path => $class) {
        $segments = explode('.', (string) $path);
        $subject  = ucfirst((string) array_shift($segments));

        // Если у генератора нет вложенности (path = 'arithmetic'),
        // он попадёт в группу «Arithmetic» с опцией «Arithmetic».
        $label = $segments === []
            ? $subject
            : implode('.', array_map(
                static fn (string $s): string => ucfirst($s),
                $segments,
            ));

        $result[$subject][$path] = $label;
    }

    ksort($result);
    foreach ($result as &$group) {
        ksort($group);
    }
    unset($group);

    return $result;
}

/**
 * Плоский список (без групп) — на случай, если понадобится
 * для не-Filament-контекста или для API-эндпоинта.
 *
 *   ['math.algebra' => 'Math.Algebra', ...]
 *
 * @return array<string, string>
 */
public function flat(): array
{
    $result = [];

    foreach ($this->discovery->discover() as $path => $class) {
        $label = implode('.', array_map(
            static fn (string $s): string => ucfirst($s),
            explode('.', (string) $path),
        ));
        $result[$path] = $label;
    }

    ksort($result);

    return $result;
}

/**
 * Полный путь → «человеческое» имя для подписей в таблицах и дереве.
 *
 * В отличие от flat(), не переставляет местами сегменты, а
 * возвращает ровно то же имя, что видит пользователь в Select.
 * Используется, когда нужно вывести «какой резолвер привязан»
 * рядом с названием узла:
 *
 *   ['math.algebra' => 'Math.Algebra']
 *
 * @return array<string, string>
 */
public function labels(): array
{
    $result = [];

    foreach ($this->discovery->discover() as $path => $class) {
        $result[$path] = implode('.', array_map(
            static fn (string $s): string => ucfirst($s),
            explode('.', (string) $path),
        ));
    }

    ksort($result);

    return $result;
}
}
