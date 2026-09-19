<?php

declare(strict_types=1);

namespace App\Desk\Strategies;

/**
 * Substitutes `$name` references to a v2 `params` entry's default value,
 * everywhere a rule `value` or a formula `expr` can hold one, before the
 * definition reaches the validator or the engine (docs/STRATEGY_SCHEMA_V2.md,
 * "Formulas" / "Validation").
 *
 * A rule value that is *exactly* `"$name"` is replaced with the param's
 * default as-is (so a number param stays a number). A `$name` occurring
 * inside a larger string (a formula `expr`) is replaced with its default
 * rendered as a numeric literal. A reference to a name that isn't in
 * `params` is left untouched — StrategySchemaValidator reports those as
 * "unknown parameter" errors with the exact path, which a purely
 * structural substitutor has no path context to do itself.
 */
final class ParamSubstitutor
{
    private const NAME = '[A-Za-z_][A-Za-z0-9_]*';

    /** @return array<string, mixed> */
    public static function apply(array $definition): array
    {
        $rawParams = $definition['params'] ?? [];
        if (! is_array($rawParams)) {
            return $definition;
        }

        $defaults = [];
        foreach ($rawParams as $name => $spec) {
            if (is_string($name) && is_array($spec) && array_key_exists('default', $spec)) {
                $defaults[$name] = $spec['default'];
            }
        }

        if ($defaults === []) {
            return $definition;
        }

        return self::walk($definition, $defaults);
    }

    /** @param array<string, mixed> $defaults */
    private static function walk(mixed $node, array $defaults): mixed
    {
        if (is_array($node)) {
            $out = [];
            foreach ($node as $k => $v) {
                $out[$k] = self::walk($v, $defaults);
            }

            return $out;
        }

        if (! is_string($node) || ! str_contains($node, '$')) {
            return $node;
        }

        if (preg_match('/^\$('.self::NAME.')$/', $node, $m) === 1 && array_key_exists($m[1], $defaults)) {
            return $defaults[$m[1]];
        }

        return preg_replace_callback('/\$('.self::NAME.')/', function (array $m) use ($defaults): string {
            if (! array_key_exists($m[1], $defaults)) {
                return $m[0];
            }

            return self::literal($defaults[$m[1]]);
        }, $node);
    }

    private static function literal(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        return (string) $value;
    }
}
