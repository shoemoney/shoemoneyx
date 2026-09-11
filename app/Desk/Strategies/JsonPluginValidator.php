<?php

declare(strict_types=1);

namespace App\Desk\Strategies;

/**
 * Validates a strategy-plugin JSON definition (see docs/STRATEGY_PLUGIN.md).
 * v1 is validate + save only: this checks shape, it does not execute anything.
 */
final class JsonPluginValidator
{
    private const OPS = ['<', '<=', '>', '>=', '==', '!='];

    private const BASES = ['custom'];

    /** ProductStats::jsonSerialize() keys usable as rule fields. */
    private const STAT_FIELDS = [
        'product_id', 'price', 'best_bid', 'best_ask', 'age_hours',
        'volume_m5_usd', 'volume_h1_usd', 'volume_h6_usd', 'volume_h24_usd',
        'volume_prev_h24_usd', 'price_change_m5_pct', 'price_change_h1_pct',
        'price_change_h6_pct', 'price_change_h24_pct', 'buys_h1', 'sells_h1',
        'buys_m5', 'sells_m5', 'buy_volume_h1_usd', 'sell_volume_h1_usd',
        'spread_bps', 'book_depth_usd', 'bid_depth_usd', 'ask_depth_usd',
        'quote_age_sec', 'tape_sample_size', 'volume_ratio_6h', 'volume_surge_h1',
        'volume_acceleration', 'buy_sell_ratio_h1', 'buy_sell_ratio_m5',
        'candles_h1_count',
    ];

    private const INDICATORS = ['rsi14', 'ema9', 'ema21', 'ema50', 'atr14', 'macd', 'signal', 'hist'];

    private const POSITION_FIELDS = ['position.pnl_pct', 'position.hold_hours'];

    /**
     * @return array{valid: bool, errors: array<int, string>}
     */
    public static function validate(mixed $definition): array
    {
        $errors = [];

        if (! is_array($definition)) {
            return ['valid' => false, 'errors' => ['definition must be a JSON object']];
        }

        if (! isset($definition['key']) || ! is_string($definition['key']) || ! preg_match('/^[a-z0-9-]{2,64}$/', $definition['key'])) {
            $errors[] = 'key is required: 2-64 chars, lowercase letters, numbers, dashes';
        }

        if (! isset($definition['name']) || ! is_string($definition['name']) || trim($definition['name']) === '' || mb_strlen($definition['name']) > 120) {
            $errors[] = 'name is required, max 120 chars';
        }

        if (isset($definition['description']) && (! is_string($definition['description']) || mb_strlen($definition['description']) > 2000)) {
            $errors[] = 'description must be a string, max 2000 chars';
        }

        if (($definition['version'] ?? null) !== 1) {
            $errors[] = 'version must be 1';
        }

        if (isset($definition['base']) && ! in_array($definition['base'], self::BASES, true)) {
            $errors[] = 'base must be one of: '.implode(', ', self::BASES);
        }

        if (isset($definition['suggest'])) {
            if (! is_array($definition['suggest'])) {
                $errors[] = 'suggest must be an object';
            } else {
                $suggest = $definition['suggest'];
                if (isset($suggest['products']) && (! is_array($suggest['products']) || array_any($suggest['products'], fn ($p) => ! is_string($p)))) {
                    $errors[] = 'suggest.products must be an array of strings';
                }
                if (isset($suggest['days']) && (! is_int($suggest['days']) || $suggest['days'] < 1 || $suggest['days'] > 365)) {
                    $errors[] = 'suggest.days must be an integer 1-365';
                }
                if (isset($suggest['cash']) && (! is_numeric($suggest['cash']) || $suggest['cash'] < 1)) {
                    $errors[] = 'suggest.cash must be a positive number';
                }
            }
        }

        if (isset($definition['params'])) {
            if (! is_array($definition['params'])) {
                $errors[] = 'params must be an object of string keys';
            } else {
                foreach ($definition['params'] as $k => $v) {
                    if (! is_string($k) || $k === '' || is_array($v) || is_object($v)) {
                        $errors[] = "params.{$k} must be a scalar value";
                    }
                }
            }
        }

        if (isset($definition['scan'])) {
            if (! is_array($definition['scan'])) {
                $errors[] = 'scan must be an object';
            } else {
                if (isset($definition['scan']['max_candidates']) && ! self::isPositiveInt($definition['scan']['max_candidates'])) {
                    $errors[] = 'scan.max_candidates must be a positive integer';
                }
                self::checkRules($definition['scan']['filters'] ?? null, 'scan.filters', false, $errors);
            }
        }

        if (isset($definition['vet'])) {
            if (! is_array($definition['vet']) || ! is_array($definition['vet']['rules'] ?? null)) {
                $errors[] = 'vet.rules is required when vet is present and must be an array';
            } else {
                self::checkRules($definition['vet']['rules'], 'vet.rules', false, $errors);
            }
        }

        if (isset($definition['size'])) {
            if (! is_array($definition['size'])) {
                $errors[] = 'size must be an object';
            } else {
                foreach (['kelly_fraction', 'max_pct_book'] as $k) {
                    if (isset($definition['size'][$k]) && ! is_numeric($definition['size'][$k])) {
                        $errors[] = "size.{$k} must be a number";
                    }
                }
            }
        }

        if (isset($definition['risk'])) {
            if (! is_array($definition['risk']) || ! is_array($definition['risk']['rules'] ?? null)) {
                $errors[] = 'risk.rules is required when risk is present and must be an array';
            } else {
                self::checkRules($definition['risk']['rules'], 'risk.rules', true, $errors);
                foreach ($definition['risk']['rules'] as $i => $rule) {
                    if (is_array($rule) && isset($rule['action']) && $rule['action'] !== 'close') {
                        $errors[] = "risk.rules[{$i}].action must be \"close\" (v1)";
                    }
                }
            }
        }

        return ['valid' => $errors === [], 'errors' => array_values($errors)];
    }

    /** @param array<int, string> $errors */
    private static function checkRules(mixed $rules, string $path, bool $allowPosition, array &$errors): void
    {
        if ($rules === null) {
            return;
        }
        if (! is_array($rules)) {
            $errors[] = "{$path} must be an array of rules";

            return;
        }
        foreach ($rules as $i => $rule) {
            if (! is_array($rule)) {
                $errors[] = "{$path}[{$i}] must be an object";

                continue;
            }
            if (! isset($rule['field']) || ! is_string($rule['field']) || ! self::isAllowedField($rule['field'], $allowPosition)) {
                $errors[] = "{$path}[{$i}].field is unknown (stats key, indicators.*, or position.* in risk only)";
            }
            if (! isset($rule['op']) || ! in_array($rule['op'], self::OPS, true)) {
                $errors[] = "{$path}[{$i}].op must be one of: ".implode(', ', self::OPS);
            }
            if (! array_key_exists('value', $rule) || is_array($rule['value']) || is_object($rule['value'])) {
                $errors[] = "{$path}[{$i}].value must be a scalar or null";
            }
        }
    }

    private static function isAllowedField(string $field, bool $allowPosition): bool
    {
        if (in_array($field, self::STAT_FIELDS, true)) {
            return true;
        }
        if (str_starts_with($field, 'indicators.')) {
            return in_array(substr($field, strlen('indicators.')), self::INDICATORS, true);
        }
        if (str_starts_with($field, 'extra.indicators.')) {
            return in_array(substr($field, strlen('extra.indicators.')), self::INDICATORS, true);
        }

        return $allowPosition && in_array($field, self::POSITION_FIELDS, true);
    }

    private static function isPositiveInt(mixed $v): bool
    {
        return is_int($v) && $v > 0;
    }
}
