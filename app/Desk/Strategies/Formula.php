<?php

declare(strict_types=1);

namespace App\Desk\Strategies;

/**
 * The v2 sizing-formula grammar (docs/STRATEGY_SCHEMA_V2.md, "Formulas"):
 * decimal numbers, `+ - * /`, parens, unary minus, `min(a, b)`, `max(a, b)`,
 * `abs(a)`, and identifiers from a closed per-section list.
 *
 * parse() is a hand-rolled recursive-descent parser/tokenizer with no
 * dependencies — every failure, tokenizer or grammar, surfaces as a
 * FormulaParseError carrying the exact byte offset. evaluate() is pure and
 * never throws: a missing variable or a division by zero propagates as
 * null (fail closed — "no order" is the correct outcome, not a crash).
 */
final class Formula
{
    /** @var array<string, array<int, string>> variable name => sections it's allowed in */
    private const VARIABLES = [
        'equity' => ['entry', 'adds', 'reentry'],
        'cash' => ['entry', 'adds', 'reentry'],
        'price' => ['entry', 'adds', 'reentry'],
        'pi' => ['entry', 'adds', 'reentry'],
        'fees_rt_pct' => ['entry', 'adds', 'reentry'],
        'avg' => ['adds', 'reentry'],
        'position_usd' => ['adds', 'reentry'],
        'initial_cost_usd' => ['adds', 'reentry'],
        'spacing_pct' => ['reentry'],
        'retrace_pct' => ['reentry'],
        'sold_qty' => ['reentry'],
        'sold_usd' => ['reentry'],
    ];

    private const FUNCTIONS = [
        'min' => 2,
        'max' => 2,
        'abs' => 1,
    ];

    /** @return array<int, string> every variable name usable in $section, or [] for an unknown section */
    public static function allowedVariables(string $section): array
    {
        $names = [];
        foreach (self::VARIABLES as $name => $sections) {
            if (in_array($section, $sections, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return array{type: string, ...} the AST root
     *
     * @throws FormulaParseError
     */
    public static function parse(string $expr): array
    {
        $tokens = self::tokenize($expr);
        $pos = 0;
        $ast = self::parseExpr($tokens, $pos);
        if ($tokens[$pos]['type'] !== 'eof') {
            throw new FormulaParseError("unexpected '{$tokens[$pos]['text']}'", $tokens[$pos]['offset']);
        }

        return $ast;
    }

    /** Every identifier node in $ast, in the order encountered, with its offset. */
    public static function variablesUsed(array $ast): array
    {
        $names = [];
        self::walk($ast, function (array $node) use (&$names): void {
            if ($node['type'] === 'var') {
                $names[] = ['name' => $node['name'], 'offset' => $node['offset']];
            }
        });

        return $names;
    }

    /** @param array<string, float|int> $vars */
    public static function evaluate(array $ast, array $vars): ?float
    {
        return self::evalNode($ast, $vars);
    }

    /** @param array<string, float|int> $vars */
    private static function evalNode(array $node, array $vars): ?float
    {
        switch ($node['type']) {
            case 'num':
                return (float) $node['value'];
            case 'var':
                return array_key_exists($node['name'], $vars) ? (float) $vars[$node['name']] : null;
            case 'unary':
                $v = self::evalNode($node['expr'], $vars);

                return $v === null ? null : -$v;
            case 'bin':
                $l = self::evalNode($node['left'], $vars);
                $r = self::evalNode($node['right'], $vars);
                if ($l === null || $r === null) {
                    return null;
                }

                return match ($node['op']) {
                    '+' => $l + $r,
                    '-' => $l - $r,
                    '*' => $l * $r,
                    '/' => $r == 0.0 ? null : $l / $r,
                    default => null,
                };
            case 'call':
                $args = [];
                foreach ($node['args'] as $arg) {
                    $v = self::evalNode($arg, $vars);
                    if ($v === null) {
                        return null;
                    }
                    $args[] = $v;
                }

                return match ($node['name']) {
                    'min' => min($args),
                    'max' => max($args),
                    'abs' => abs($args[0]),
                    default => null,
                };
            default:
                return null;
        }
    }

    private static function walk(array $node, \Closure $visit): void
    {
        $visit($node);
        match ($node['type']) {
            'unary' => self::walk($node['expr'], $visit),
            'bin' => (function () use ($node, $visit): void {
                self::walk($node['left'], $visit);
                self::walk($node['right'], $visit);
            })(),
            'call' => (function () use ($node, $visit): void {
                foreach ($node['args'] as $arg) {
                    self::walk($arg, $visit);
                }
            })(),
            default => null,
        };
    }

    /** @param array<int, array{type: string, text: string, offset: int}> $tokens */
    private static function parseExpr(array $tokens, int &$pos): array
    {
        $node = self::parseTerm($tokens, $pos);
        while (in_array($tokens[$pos]['type'], ['plus', 'minus'], true)) {
            $op = $tokens[$pos]['type'] === 'plus' ? '+' : '-';
            $pos++;
            $right = self::parseTerm($tokens, $pos);
            $node = ['type' => 'bin', 'op' => $op, 'left' => $node, 'right' => $right];
        }

        return $node;
    }

    /** @param array<int, array{type: string, text: string, offset: int}> $tokens */
    private static function parseTerm(array $tokens, int &$pos): array
    {
        $node = self::parseUnary($tokens, $pos);
        while (in_array($tokens[$pos]['type'], ['star', 'slash'], true)) {
            $op = $tokens[$pos]['type'] === 'star' ? '*' : '/';
            $pos++;
            $right = self::parseUnary($tokens, $pos);
            $node = ['type' => 'bin', 'op' => $op, 'left' => $node, 'right' => $right];
        }

        return $node;
    }

    /** @param array<int, array{type: string, text: string, offset: int}> $tokens */
    private static function parseUnary(array $tokens, int &$pos): array
    {
        if ($tokens[$pos]['type'] === 'minus') {
            $pos++;

            return ['type' => 'unary', 'expr' => self::parseUnary($tokens, $pos)];
        }

        return self::parsePrimary($tokens, $pos);
    }

    /** @param array<int, array{type: string, text: string, offset: int}> $tokens */
    private static function parsePrimary(array $tokens, int &$pos): array
    {
        $tok = $tokens[$pos];

        if ($tok['type'] === 'number') {
            $pos++;

            return ['type' => 'num', 'value' => (float) $tok['text']];
        }

        if ($tok['type'] === 'ident') {
            $name = $tok['text'];
            $offset = $tok['offset'];
            $pos++;
            if ($tokens[$pos]['type'] === 'lparen') {
                if (! array_key_exists($name, self::FUNCTIONS)) {
                    throw new FormulaParseError("unknown function '{$name}'", $offset);
                }
                $pos++;
                $args = [];
                if ($tokens[$pos]['type'] !== 'rparen') {
                    $args[] = self::parseExpr($tokens, $pos);
                    while ($tokens[$pos]['type'] === 'comma') {
                        $pos++;
                        $args[] = self::parseExpr($tokens, $pos);
                    }
                }
                if ($tokens[$pos]['type'] !== 'rparen') {
                    throw new FormulaParseError('expected )', $tokens[$pos]['offset']);
                }
                $pos++;
                $arity = self::FUNCTIONS[$name];
                if (count($args) !== $arity) {
                    throw new FormulaParseError("{$name}() takes {$arity} argument(s), got ".count($args), $offset);
                }

                return ['type' => 'call', 'name' => $name, 'args' => $args];
            }

            return ['type' => 'var', 'name' => $name, 'offset' => $offset];
        }

        if ($tok['type'] === 'lparen') {
            $pos++;
            $inner = self::parseExpr($tokens, $pos);
            if ($tokens[$pos]['type'] !== 'rparen') {
                throw new FormulaParseError('expected )', $tokens[$pos]['offset']);
            }
            $pos++;

            return $inner;
        }

        throw new FormulaParseError($tok['type'] === 'eof' ? 'unexpected end of expression' : "unexpected '{$tok['text']}'", $tok['offset']);
    }

    /** @return array<int, array{type: string, text: string, offset: int}> */
    private static function tokenize(string $expr): array
    {
        $tokens = [];
        $len = strlen($expr);
        $i = 0;
        while ($i < $len) {
            $c = $expr[$i];
            if (ctype_space($c)) {
                $i++;

                continue;
            }
            $start = $i;
            $simple = ['+' => 'plus', '-' => 'minus', '*' => 'star', '/' => 'slash', '(' => 'lparen', ')' => 'rparen', ',' => 'comma'];
            if (isset($simple[$c])) {
                $tokens[] = ['type' => $simple[$c], 'text' => $c, 'offset' => $start];
                $i++;

                continue;
            }
            if (ctype_digit($c) || ($c === '.' && $i + 1 < $len && ctype_digit($expr[$i + 1]))) {
                $j = $i;
                while ($j < $len && ctype_digit($expr[$j])) {
                    $j++;
                }
                if ($j < $len && $expr[$j] === '.') {
                    $j++;
                    while ($j < $len && ctype_digit($expr[$j])) {
                        $j++;
                    }
                }
                $tokens[] = ['type' => 'number', 'text' => substr($expr, $i, $j - $i), 'offset' => $start];
                $i = $j;

                continue;
            }
            if (ctype_alpha($c) || $c === '_') {
                $j = $i;
                while ($j < $len && (ctype_alnum($expr[$j]) || $expr[$j] === '_')) {
                    $j++;
                }
                $tokens[] = ['type' => 'ident', 'text' => substr($expr, $i, $j - $i), 'offset' => $start];
                $i = $j;

                continue;
            }

            throw new FormulaParseError("unexpected character '{$c}'", $start);
        }
        $tokens[] = ['type' => 'eof', 'text' => '', 'offset' => $len];

        return $tokens;
    }
}
