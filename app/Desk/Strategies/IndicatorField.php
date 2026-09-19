<?php

declare(strict_types=1);

namespace App\Desk\Strategies;

/**
 * Parses the `ind.<name>(<args>)[.<output>]` field grammar (docs/STRATEGY_SCHEMA_V2.md,
 * "Parametric indicator fields") into (name, args, output) against the closed table of
 * indicators the desk knows how to compute. JsonRuleEvaluator resolves values through this;
 * the phase B schema validator reuses the same table to check arity/outputs at save time.
 */
final class IndicatorField
{
    /**
     * name => arity, outputs[], int_args[]. An output-less field (`ind.rsi(14)`, no `.suffix`)
     * resolves to 'value' — and only when 'value' is in the list. Every indicator with more
     * than one output requires an explicit `.output` suffix. `int_args` names the 0-based
     * argument indices that must be a positive whole number rather than any float — every
     * period-like arg except `bb`'s free-float `k`.
     */
    private const TABLE = [
        'rsi' => ['arity' => 1, 'outputs' => ['value'], 'int_args' => [0]],
        'sma' => ['arity' => 1, 'outputs' => ['value'], 'int_args' => [0]],
        'ema' => ['arity' => 1, 'outputs' => ['value'], 'int_args' => [0]],
        'atr' => ['arity' => 1, 'outputs' => ['value', 'pct'], 'int_args' => [0]],
        'adx' => ['arity' => 1, 'outputs' => ['value'], 'int_args' => [0]],
        'macd' => ['arity' => 3, 'outputs' => ['macd', 'signal', 'hist'], 'int_args' => [0, 1, 2]],
        'bb' => ['arity' => 2, 'outputs' => ['upper', 'lower', 'mid', 'pos'], 'int_args' => [0]],
        'vwap' => ['arity' => 0, 'outputs' => ['value'], 'int_args' => []],
        'obv' => ['arity' => 0, 'outputs' => ['value'], 'int_args' => []],
        'smx' => ['arity' => 0, 'outputs' => ['wt1', 'wt2', 'wt_cross', 'rsi_mfi', 'buy', 'sell', 'gold_buy', 'div_bull', 'div_bear'], 'int_args' => []],
    ];

    private function __construct(
        public readonly string $name,
        /** @var array<int, float> */
        public readonly array $args,
        public readonly string $output,
    ) {}

    /** @return array<string, array{arity: int, outputs: array<int, string>, int_args: array<int, int>}> */
    public static function table(): array
    {
        return self::TABLE;
    }

    /**
     * Null when $field isn't an `ind.*` field at all — callers fall through to try other
     * field kinds. Throws \InvalidArgumentException when it IS one but malformed, names an
     * unknown indicator, has the wrong arity, or names an output the indicator doesn't have;
     * JsonRuleEvaluator treats that the same as "field missing" (fail-closed), the validator
     * turns it into a reported error.
     */
    public static function parse(string $field): ?self
    {
        if (! str_starts_with($field, 'ind.')) {
            return null;
        }

        $rest = substr($field, strlen('ind.'));
        if (! preg_match('/^([a-z][a-z0-9_]*)(?:\(([^()]*)\))?(?:\.([a-z][a-z0-9_]*))?$/', $rest, $m)) {
            throw new \InvalidArgumentException("malformed indicator field: {$field}");
        }

        $name = $m[1];
        $spec = self::TABLE[$name] ?? null;
        if ($spec === null) {
            throw new \InvalidArgumentException("unknown indicator: {$name}");
        }

        $argsRaw = trim($m[2] ?? '');
        $args = $argsRaw === '' ? [] : array_map(
            static function (string $a) use ($field): float {
                $a = trim($a);
                if (! is_numeric($a)) {
                    throw new \InvalidArgumentException("non-numeric argument in indicator field: {$field}");
                }

                return (float) $a;
            },
            explode(',', $argsRaw),
        );
        if (count($args) !== $spec['arity']) {
            throw new \InvalidArgumentException(sprintf('ind.%s takes %d argument(s), got %d', $name, $spec['arity'], count($args)));
        }
        foreach ($args as $i => $arg) {
            if (in_array($i, $spec['int_args'], true)) {
                if (! self::isPositiveWholeNumber($arg)) {
                    throw new \InvalidArgumentException(sprintf('ind.%s argument %d must be a positive whole number, got %s', $name, $i + 1, self::formatArg($arg)));
                }
            } elseif ($arg <= 0.0) {
                throw new \InvalidArgumentException(sprintf('ind.%s argument %d must be a positive number, got %s', $name, $i + 1, self::formatArg($arg)));
            }
        }

        $output = $m[3] ?? '';
        if ($output === '') {
            if (! in_array('value', $spec['outputs'], true)) {
                throw new \InvalidArgumentException(sprintf('ind.%s requires an output suffix (one of: %s)', $name, implode(', ', $spec['outputs'])));
            }
            $output = 'value';
        } elseif (! in_array($output, $spec['outputs'], true)) {
            throw new \InvalidArgumentException(sprintf('ind.%s has no .%s output (one of: %s)', $name, $output, implode(', ', $spec['outputs'])));
        }

        return new self($name, $args, $output);
    }

    /** Identity for (name, args) shared by every output of a multi-output indicator, so one computed series serves all of them. */
    public function seriesKey(): string
    {
        return $this->name.'('.implode(',', $this->args).')';
    }

    private static function isPositiveWholeNumber(float $arg): bool
    {
        return $arg >= 1.0 && floor($arg) === $arg;
    }

    private static function formatArg(float $arg): string
    {
        return rtrim(rtrim(sprintf('%.6f', $arg), '0'), '.');
    }
}
