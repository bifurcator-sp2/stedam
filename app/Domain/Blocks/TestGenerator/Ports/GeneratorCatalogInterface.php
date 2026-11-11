<?php

declare(strict_types=1);

namespace App\Domain\Blocks\TestGenerator\Ports;

/**
 * Порт каталога зарегистрированных генераторов.
 *
 * Домену и API нужно знать, «что мы умеем»: список доступных resolver-путей,
 * их предмет (первый сегмент) и можно ли по конкретному пути что-то сгенерировать.
 *
 * Отдельно от ProblemGeneratorInterface, потому что это операция чтения
 * каталога, а не генерации: каталог может кешироваться независимо
 * от рантайма генерации.
 */
interface GeneratorCatalogInterface
{
    /**
     * Все зарегистрированные resolver-пути.
     *
     * @return list<string>
     */
    public function availablePaths(): array;

    /**
     * Карта «путь => FQCN класса генератора».
     *
     * @return array<string, class-string>
     */
    public function pathToClass(): array;

    /**
     * Возвращает сам путь, если он зарегистрирован; null — если такого нет.
     */
    public function matchedPath(string $path): ?string;

    /**
     * Предмет — первый сегмент resolver-пути: 'math.algebra.x' => 'math'.
     */
    public function subjectFor(string $path): string;
}
