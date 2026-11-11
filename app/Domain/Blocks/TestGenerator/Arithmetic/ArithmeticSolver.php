<?php

declare(strict_types=1);

namespace App\Domain\Blocks\TestGenerator\Arithmetic;

/**
 * Пошаговый решатель дерева арифметического выражения.
 *
 * Работает по принципу "свёртки": на каждом шаге находит в дереве самый
 * левый-нижний узел, оба ребёнка которого — числа, и заменяет этот узел
 * на NumberNode с вычисленным значением. Это соответствует порядку,
 * в котором ученик выполняет действия:
 *   1) сначала умножение/деление;
 *   2) затем сложение/вычитание;
 *   3) внутри одного приоритета — слева направо.
 *
 * Возвращает массив LaTeX-представлений выражения на каждом шаге
 * (включая исходное и финальное). Формат готов для сборки через
 * KaTeX::aligned().
 */
final class ArithmeticSolver
{
    /**
     * @return string[] LaTeX-выражения на каждом шаге решения (≥ 1 элемента).
     */
    public static function steps(ExprNode $root): array
    {
        $steps   = [$root->toLatex()];
        $current = $root;

        while (true) {
            // Приоритет 1: умножение/деление.
            $next = self::findAndReduce($current, ['*', '/']);

            // Приоритет 2: сложение/вычитание.
            $next ??= self::findAndReduce($current, ['+', '-']);

            if ($next === null) {
                break;
            }

            $current = $next;
            $steps[] = $current->toLatex();
        }

        return $steps;
    }

    /**
     * Находит первый узел с указанными операторами, у которого оба ребёнка —
     * NumberNode, и "сворачивает" его в NumberNode.
     *
     * Возвращает новое дерево с заменой (оригинальное не мутируется, т.к.
     * узлы readonly), либо null, если такого узла нет.
     *
     * @param string[] $operators
     */
    private static function findAndReduce(ExprNode $node, array $operators): ?ExprNode
    {
        if (! $node instanceof BinaryNode) {
            return null;
        }

        // Случай 1: сам узел готов к свёртке.
        if ($node->left instanceof NumberNode
            && $node->right instanceof NumberNode
            && in_array($node->op, $operators, true)
        ) {
            return new NumberNode($node->evaluate());
        }

        // Случай 2: свёртка в левом поддереве (идём слева направо).
        $leftReduced = self::findAndReduce($node->left, $operators);
        if ($leftReduced !== null) {
            return new BinaryNode($leftReduced, $node->op, $node->right);
        }

        // Случай 3: свёртка в правом поддереве.
        $rightReduced = self::findAndReduce($node->right, $operators);
        if ($rightReduced !== null) {
            return new BinaryNode($node->left, $node->op, $rightReduced);
        }

        return null;
    }
}
