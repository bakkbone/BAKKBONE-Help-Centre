<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Core;

/** Evaluates gettext plural expressions ("n%10==1 && n%100!=11 ? 0 : 1") without eval(). */
final class PluralRule
{
    private const LEVELS = [['||'], ['&&'], ['==', '!='], ['<', '>', '<=', '>='], ['+', '-'], ['*', '/', '%']];

    /** @var list<string> */
    private array $tokens = [];
    private int $pos = 0;
    private int $n = 0;

    private function __construct(private readonly string $expr)
    {
    }

    /** Null when the expression isn't valid. */
    public static function compile(string $expr): ?\Closure
    {
        $rule = new self($expr);
        if (!$rule->tokenize()) {
            return null;
        }
        try {
            foreach ([0, 1, 2, 5, 11, 21, 101, 1000] as $probe) {
                $rule->evaluate($probe);
            }
        } catch (\Throwable) {
            return null;
        }
        return fn(int $n): int => max(0, $rule->evaluate($n));
    }

    private function tokenize(): bool
    {
        $src = preg_replace('/\s+/', '', $this->expr) ?? '';
        if ($src === '' || !preg_match_all('/n|\d+|&&|\|\||==|!=|<=|>=|[?:()<>!%*\/+\-]/', $src, $m) || implode('', $m[0]) !== $src) {
            return false;
        }
        $this->tokens = $m[0];
        return true;
    }

    private function evaluate(int $n): int
    {
        $this->n = $n;
        $this->pos = 0;
        $v = $this->ternary();
        if ($this->pos !== count($this->tokens)) {
            throw new \InvalidArgumentException('Unexpected ' . $this->tokens[$this->pos]);
        }
        return $v;
    }

    private function ternary(): int
    {
        $cond = $this->binary(0);
        if ($this->peek() !== '?') {
            return $cond;
        }
        $this->expect('?');
        $a = $this->ternary();
        $this->expect(':');
        $b = $this->ternary();
        return $cond ? $a : $b;
    }

    private function binary(int $level): int
    {
        if ($level >= count(self::LEVELS)) {
            return $this->unary();
        }
        $left = $this->binary($level + 1);
        while (in_array($this->peek(), self::LEVELS[$level], true)) {
            $op = $this->tokens[$this->pos++];
            $right = $this->binary($level + 1);
            $left = match ($op) {
                '||' => (int)($left || $right), '&&' => (int)($left && $right),
                '==' => (int)($left === $right), '!=' => (int)($left !== $right),
                '<' => (int)($left < $right), '>' => (int)($left > $right), '<=' => (int)($left <= $right), '>=' => (int)($left >= $right),
                '+' => $left + $right, '-' => $left - $right, '*' => $left * $right,
                '/' => $right === 0 ? 0 : intdiv($left, $right), '%' => $right === 0 ? 0 : $left % $right,
            };
        }
        return $left;
    }

    private function unary(): int
    {
        $t = $this->tokens[$this->pos++] ?? null;
        return match (true) {
            $t === '!' => (int)!$this->unary(),
            $t === '-' => -$this->unary(),
            $t === '(' => $this->group(),
            $t === 'n' => $this->n,
            $t !== null && ctype_digit($t) => (int)$t,
            default => throw new \InvalidArgumentException('Unexpected ' . ($t ?? 'end')),
        };
    }

    private function group(): int
    {
        $v = $this->ternary();
        $this->expect(')');
        return $v;
    }

    private function peek(): ?string
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function expect(string $t): void
    {
        if (($this->tokens[$this->pos] ?? null) !== $t) {
            throw new \InvalidArgumentException("Expected $t");
        }
        $this->pos++;
    }
}
