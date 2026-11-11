<?php

declare(strict_types=1);

namespace App\Infrastructure\Blocks\TestGenerator\Arithmetic;

/**
 * Внутренний узел дерева — бинарная операция над двумя подвыражениями.
 *
 * Отвечает за:
 *  - рендер в LaTeX с корректной расстановкой скобок по приоритетам
 *    (см. renderChild());
 *  - вычисление значения через стандартные операции PHP;
 *  - сообщение своего приоритета родителю для решения о скобках.
 *
 * Поддерживаются только целочисленные операции: '+', '-', '*', '/'.
 * Деление гарантирует целый результат на уровне генератора (делимое
 * кратно делителю), поэтому intdiv применять не нужно — используем
 * обычное / и полагаемся на то, что генератор не создаст 7 / 2.
 */
final class BinaryNode extends ExprNode
{
    public function __construct(
        public readonly ExprNode $left,
        public readonly string $op,
        public readonly ExprNode $right,
    ) {
        if (! in_array($op, ['+', '-', '*', '/'], true)) {
            throw new \InvalidArgumentException(
                sprintf('Unsupported operator "%s".', $op)
            );
        }
}

public function toLatex(): string
{
    $left  = $this->renderChild($this->left, isRight: false);
    $right = $this->renderChild($this->right, isRight: true);

    return $left . ' ' . $this->opLatex() . ' ' . $right;
}

public function evaluate(): int|float
{
    $l = $this->left->evaluate();
    $r = $this->right->evaluate();

    return match ($this->op) {
        '+' => $l + $r,
        '-' => $l - $r,
        '*' => $l * $r,
        '/' => $l / $r,
    };
}

public function precedence(): int
{
    return in_array($this->op, ['*', '/'], true) ? 2 : 1;
}

/**
 * LaTeX-отображение оператора.
 *
 * Используем \cdot и \div вместо * и /: так запись выглядит
 * как в учебнике и однозначно парсится KaTeX.
 */
public function opLatex(): string
{
    return match ($this->op) {
        '*' => '\cdot',
        '/' => '\div',
        default => $this->op,
    };
}

/**
 * Рендер ребёнка с учётом приоритетов.
 *
 * Скобки нужны, когда:
 *  - приоритет ребёнка НИЖЕ приоритета текущей операции;
 *  - ребёнок справа — операция ТОГО ЖЕ приоритета, но текущая
 *    операция неассоциативна вправо ( '-' и '/' ), т.е.
 *    a - (b - c) ≠ a - b - c  и  a / (b / c) ≠ a / b / c.
 *
 * Правило намеренно НЕ ставит скобки для правого ребёнка с более
 * высоким приоритетом ( a + b*c → a + b \cdot c ) и для левого
 * ребёнка того же приоритета ( a - b - c → a - b - c ).
 */
private function renderChild(ExprNode $child, bool $isRight): string
{
    $latex = $child->toLatex();

    if ($child instanceof NumberNode) {
        return $latex;
    }

    $current  = $this->precedence();
    $childPre = $child->precedence();

    $needsParens = $childPre < $current
        || ($isRight
            && $childPre === $current
            && in_array($this->op, ['-', '/'], true));

    return $needsParens
        ? '\left(' . $latex . '\right)'
        : $latex;
}
}
