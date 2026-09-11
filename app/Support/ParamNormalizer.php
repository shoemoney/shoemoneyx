<?php

declare(strict_types=1);

namespace App\Support;

use App\Desk\StrategyRegistry;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Types an override/setting value from the DEFAULT it is overriding, instead of pattern-matching
 * the raw value: a numeric-looking string on a boolean knob ("1"/"0") becomes a real bool, the
 * same tokens on an int/float knob become a number, and a string knob (even a numeric-looking
 * one like mr.timeframe="1", or an empty one) stays a plain string — because its default says
 * so, not because of what the value happens to look like.
 *
 * Shared by BacktestController::normalizeOverrides (backtest param overrides) and
 * DeskController::updateSetting (the live running desk's settings endpoint) so "on"/"off"/"no"
 * behave identically in both places.
 */
class ParamNormalizer
{
    /**
     * @param  string  $key  dotted key, e.g. "mr.timeframe", "mr.short.timeframe", "size.kelly_cap_pct", "mode"
     */
    public static function normalize(string $key, mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $default = self::resolveDefault($key);

        return match (true) {
            is_bool($default) => self::asBool($key, $value),
            is_int($default), is_float($default) => self::asNumber($key, $value),
            is_string($default) => $value, // known string knob — keep the literal string, including ''
            default => self::legacyGuess($key, $value), // key isn't in any defaults tree — best-effort as before
        };
    }

    /**
     * Find the value $key would resolve to with no override, so its PHP type tells us the
     * expected type. Strategy tunables (root is a registered strategy key, e.g. "mr") are typed
     * from that strategy's defaults(); "mr.short.X" falls back to "mr.X" since shorts don't carry
     * their own static defaults (see MeanReversionStrategy::sp()). "per_product.<SYMBOL>.X" is typed
     * the same as "X". Everything else is typed from config('desk').
     */
    private static function resolveDefault(string $key): mixed
    {
        $segments = explode('.', $key);
        $root = $segments[0] ?? '';

        $strategies = (array) config('desk.strategies', []);
        if (isset($strategies[$root])) {
            $defaults = Arr::dot(app(StrategyRegistry::class)->make($root)->defaults());
            if (array_key_exists($key, $defaults)) {
                return $defaults[$key];
            }
            if (($segments[1] ?? null) === 'short') {
                $fallback = $root.'.'.implode('.', array_slice($segments, 2));

                return $defaults[$fallback] ?? null;
            }

            return null;
        }

        if ($root === 'per_product') {
            $rest = implode('.', array_slice($segments, 2));

            return $rest !== '' ? self::resolveDefault($rest) : null;
        }

        $config = (array) config('desk', []);
        unset($config['strategies']);
        $flat = Arr::dot($config);

        return $flat[$key] ?? null;
    }

    private static function asBool(string $key, string $value): bool
    {
        $lower = strtolower(trim($value));

        return match ($lower) {
            'true', 'on', '1', 'yes' => true,
            'false', 'off', '0', 'no' => false,
            default => throw ValidationException::withMessages(['params' => "malformed override value for {$key}: expected a boolean, got \"{$value}\""]),
        };
    }

    private static function asNumber(string $key, string $value): int|float
    {
        $trimmed = trim($value);
        if ($trimmed === '' || ! is_numeric($trimmed)) {
            throw ValidationException::withMessages(['params' => "malformed override value for {$key}: expected a number, got \"{$value}\""]);
        }

        return str_contains($trimmed, '.') || stripos($trimmed, 'e') !== false
            ? (float) $trimmed
            : (int) $trimmed;
    }

    /**
     * Pre-typing behaviour, kept only for keys we can't find in any defaults tree: booleans/numbers
     * guessed from the string's shape, everything else left as a string. An empty value has no
     * shape to guess from, so it's rejected here — a key with a real string default (e.g.
     * mr.timeframe) never reaches this branch, so "" for a string-typed knob is unaffected.
     */
    private static function legacyGuess(string $key, string $value): bool|int|float|string
    {
        $trimmed = trim($value);
        $lower = strtolower($trimmed);

        if (in_array($lower, ['true', 'on', 'yes'], true)) {
            return true;
        }
        if (in_array($lower, ['false', 'off', 'no'], true)) {
            return false;
        }
        if ($lower === '1') {
            return true;
        }
        if ($lower === '0') {
            return false;
        }
        if ($trimmed === '') {
            throw ValidationException::withMessages(['params' => "malformed override value for {$key}: empty"]);
        }
        if (is_numeric($trimmed)) {
            return str_contains($trimmed, '.') || stripos($trimmed, 'e') !== false
                ? (float) $trimmed
                : (int) $trimmed;
        }
        // Looks like an attempted number (digits/dot/minus only) but isn't a valid one, e.g.
        // "1.2.3" or "--5" — reject rather than silently keep it as a string.
        if (preg_match('/^-?[0-9.]+$/', $trimmed) === 1) {
            throw ValidationException::withMessages(['params' => "malformed override value for {$key}: {$value}"]);
        }

        return $value;
    }
}
