<?php

declare(strict_types=1);

namespace App\Desk\Strategies;

use App\Models\Candle;

/**
 * Validates the formal `schema_version: 1` strategy-plugin definition (see
 * docs/STRATEGY_SCHEMA.md): sectioned meta/params/setup/trigger/entry/
 * management/exit/risk, every section optional except `meta` and `entry`.
 *
 * Distinct from JsonPluginValidator, which keeps validating the legacy flat
 * shape (scan/vet/size/risk) that plugins saved before this schema existed
 * still use. Errors are structured {path, message} so the UI can point at
 * the exact offending field instead of a flat string list.
 */
final class StrategySchemaValidator
{
    private const BASES = ['custom'];

    private const SIDES = ['long', 'short'];

    private const PARAM_TYPES = ['number', 'bool', 'string'];

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

    private const TIME_FIELDS = ['time.hour_utc', 'time.weekday'];

    private const POSITION_FIELDS = ['position.pnl_pct', 'position.hold_hours'];

    /** @return array{valid: bool, errors: array<int, array{path: string, message: string}>} */
    public static function validate(mixed $definition): array
    {
        $errors = [];

        if (! is_array($definition)) {
            return ['valid' => false, 'errors' => [['path' => '$', 'message' => 'definition must be a JSON object']]];
        }

        if (($definition['schema_version'] ?? null) !== SchemaMigrator::CURRENT_VERSION) {
            $errors[] = ['path' => 'schema_version', 'message' => 'schema_version must be '.SchemaMigrator::CURRENT_VERSION];
        }

        if (! isset($definition['key']) || ! is_string($definition['key']) || ! preg_match('/^[a-z0-9-]{2,64}$/', $definition['key'])) {
            $errors[] = ['path' => 'key', 'message' => 'key is required: 2-64 chars, lowercase letters, numbers, dashes'];
        }

        if (isset($definition['base']) && ! in_array($definition['base'], self::BASES, true)) {
            $errors[] = ['path' => 'base', 'message' => 'base must be one of: '.implode(', ', self::BASES)];
        }

        self::checkMeta($definition['meta'] ?? null, $errors);
        self::checkParams($definition['params'] ?? null, $errors);
        self::checkRuleSection($definition['setup'] ?? null, 'setup', false, $errors);
        self::checkTrigger($definition['trigger'] ?? null, $errors);
        self::checkEntry($definition['entry'] ?? null, $errors);
        self::checkManagement($definition['management'] ?? null, $errors);
        self::checkExit($definition['exit'] ?? null, $errors);
        self::checkRisk($definition['risk'] ?? null, $errors);

        return ['valid' => $errors === [], 'errors' => $errors];
    }

    /** @param array<int, array{path: string, message: string}> $errors */
    private static function checkMeta(mixed $meta, array &$errors): void
    {
        if (! is_array($meta)) {
            $errors[] = ['path' => 'meta', 'message' => 'meta is required and must be an object'];

            return;
        }
        if (! isset($meta['name']) || ! is_string($meta['name']) || trim($meta['name']) === '' || mb_strlen($meta['name']) > 120) {
            $errors[] = ['path' => 'meta.name', 'message' => 'meta.name is required, max 120 chars'];
        }
        if (isset($meta['description']) && (! is_string($meta['description']) || mb_strlen($meta['description']) > 2000)) {
            $errors[] = ['path' => 'meta.description', 'message' => 'meta.description must be a string, max 2000 chars'];
        }
        if (isset($meta['tags']) && (! is_array($meta['tags']) || array_any($meta['tags'], fn ($t) => ! is_string($t)))) {
            $errors[] = ['path' => 'meta.tags', 'message' => 'meta.tags must be an array of strings'];
        }
        if (isset($meta['timeframe'])) {
            if (! is_string($meta['timeframe'])) {
                $errors[] = ['path' => 'meta.timeframe', 'message' => 'meta.timeframe must be a string'];
            } elseif (Candle::canonicalTimeframe($meta['timeframe']) === null) {
                $errors[] = ['path' => 'meta.timeframe', 'message' => 'meta.timeframe must be a known timeframe (e.g. 1m, 15m, 1h, 6h, 1d)'];
            }
        }
        if (isset($meta['assets']) && (! is_array($meta['assets']) || array_any($meta['assets'], fn ($a) => ! is_string($a)))) {
            $errors[] = ['path' => 'meta.assets', 'message' => 'meta.assets must be an array of strings'];
        }
    }

    /** @param array<int, array{path: string, message: string}> $errors */
    private static function checkParams(mixed $params, array &$errors): void
    {
        if ($params === null) {
            return;
        }
        if (! is_array($params)) {
            $errors[] = ['path' => 'params', 'message' => 'params must be an object of string keys'];

            return;
        }
        foreach ($params as $k => $spec) {
            $path = "params.{$k}";
            if (! is_string($k) || $k === '' || ! is_array($spec)) {
                $errors[] = ['path' => $path, 'message' => 'each param must be {type, default, min?, max?, step?}'];

                continue;
            }
            if (! in_array($spec['type'] ?? null, self::PARAM_TYPES, true)) {
                $errors[] = ['path' => "{$path}.type", 'message' => 'type must be one of: '.implode(', ', self::PARAM_TYPES)];

                continue;
            }
            if (! array_key_exists('default', $spec)) {
                $errors[] = ['path' => "{$path}.default", 'message' => 'default is required'];
            } elseif (! self::matchesType($spec['default'], $spec['type'])) {
                $errors[] = ['path' => "{$path}.default", 'message' => "default must match type {$spec['type']}"];
            }
            if ($spec['type'] === 'number') {
                foreach (['min', 'max', 'step'] as $bound) {
                    if (isset($spec[$bound]) && ! is_numeric($spec[$bound])) {
                        $errors[] = ['path' => "{$path}.{$bound}", 'message' => "{$bound} must be a number"];
                    }
                }
            }
        }
    }

    private static function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'number' => is_numeric($value),
            'bool' => is_bool($value),
            'string' => is_string($value),
            default => false,
        };
    }

    /** @param array<int, array{path: string, message: string}> $errors */
    private static function checkTrigger(mixed $trigger, array &$errors): void
    {
        if ($trigger === null) {
            return;
        }
        if (! is_array($trigger)) {
            $errors[] = ['path' => 'trigger', 'message' => 'trigger must be an object'];

            return;
        }
        if (isset($trigger['max_candidates']) && ! self::isPositiveInt($trigger['max_candidates'])) {
            $errors[] = ['path' => 'trigger.max_candidates', 'message' => 'trigger.max_candidates must be a positive integer'];
        }
        self::checkRules($trigger['rules'] ?? null, 'trigger.rules', false, $errors);
    }

    /** @param array<int, array{path: string, message: string}> $errors */
    private static function checkEntry(mixed $entry, array &$errors): void
    {
        if (! is_array($entry)) {
            $errors[] = ['path' => 'entry', 'message' => 'entry is required and must be an object'];

            return;
        }
        if (isset($entry['side']) && ! in_array($entry['side'], self::SIDES, true)) {
            $errors[] = ['path' => 'entry.side', 'message' => 'entry.side must be one of: '.implode(', ', self::SIDES)];
        }
        if (isset($entry['sizing'])) {
            if (! is_array($entry['sizing'])) {
                $errors[] = ['path' => 'entry.sizing', 'message' => 'entry.sizing must be an object'];
            } else {
                foreach (['kelly_fraction', 'max_pct_book'] as $k) {
                    if (isset($entry['sizing'][$k]) && ! is_numeric($entry['sizing'][$k])) {
                        $errors[] = ['path' => "entry.sizing.{$k}", 'message' => "entry.sizing.{$k} must be a number"];
                    }
                }
            }
        }
        self::checkRules($entry['confirm'] ?? null, 'entry.confirm', false, $errors);
    }

    /** @param array<int, array{path: string, message: string}> $errors */
    private static function checkManagement(mixed $mgmt, array &$errors): void
    {
        if ($mgmt === null) {
            return;
        }
        if (! is_array($mgmt)) {
            $errors[] = ['path' => 'management', 'message' => 'management must be an object'];

            return;
        }
        if (isset($mgmt['adds'])) {
            if (! is_array($mgmt['adds'])) {
                $errors[] = ['path' => 'management.adds', 'message' => 'management.adds must be an array'];
            } else {
                foreach ($mgmt['adds'] as $i => $rung) {
                    $path = "management.adds[{$i}]";
                    if (! is_array($rung)) {
                        $errors[] = ['path' => $path, 'message' => "{$path} must be an object"];

                        continue;
                    }
                    if (! isset($rung['trigger']) || ! is_array($rung['trigger'])) {
                        $errors[] = ['path' => "{$path}.trigger", 'message' => 'trigger is required: {field, op, value}'];
                    } else {
                        self::checkRules([$rung['trigger']], "{$path}.trigger", true, $errors);
                    }
                    if (! isset($rung['size_pct']) || ! is_numeric($rung['size_pct']) || $rung['size_pct'] <= 0) {
                        $errors[] = ['path' => "{$path}.size_pct", 'message' => 'size_pct must be a positive number (percent of initial cost)'];
                    }
                }
            }
        }
        if (isset($mgmt['partials'])) {
            if (! is_array($mgmt['partials'])) {
                $errors[] = ['path' => 'management.partials', 'message' => 'management.partials must be an array'];
            } else {
                foreach ($mgmt['partials'] as $i => $rung) {
                    $path = "management.partials[{$i}]";
                    if (! is_array($rung)) {
                        $errors[] = ['path' => $path, 'message' => "{$path} must be an object"];

                        continue;
                    }
                    if (! isset($rung['pct']) || ! is_numeric($rung['pct'])) {
                        $errors[] = ['path' => "{$path}.pct", 'message' => 'pct must be a number (position.pnl_pct threshold)'];
                    }
                    if (! isset($rung['fraction']) || ! is_numeric($rung['fraction']) || $rung['fraction'] <= 0 || $rung['fraction'] > 1) {
                        $errors[] = ['path' => "{$path}.fraction", 'message' => 'fraction must be a number > 0 and <= 1'];
                    }
                }
            }
        }
        if (isset($mgmt['trailing']) && $mgmt['trailing'] !== null) {
            if (! is_array($mgmt['trailing'])) {
                $errors[] = ['path' => 'management.trailing', 'message' => 'management.trailing must be an object or null'];
            } else {
                foreach (['activate_pct', 'trail_pct'] as $k) {
                    if (isset($mgmt['trailing'][$k]) && ! is_numeric($mgmt['trailing'][$k])) {
                        $errors[] = ['path' => "management.trailing.{$k}", 'message' => "management.trailing.{$k} must be a number"];
                    }
                }
                if (! isset($mgmt['trailing']['trail_pct'])) {
                    $errors[] = ['path' => 'management.trailing.trail_pct', 'message' => 'trail_pct is required'];
                }
            }
        }
    }

    /** @param array<int, array{path: string, message: string}> $errors */
    private static function checkExit(mixed $exit, array &$errors): void
    {
        if ($exit === null) {
            return;
        }
        if (! is_array($exit)) {
            $errors[] = ['path' => 'exit', 'message' => 'exit must be an object'];

            return;
        }
        foreach (['stop', 'take_profit'] as $section) {
            if (! isset($exit[$section])) {
                continue;
            }
            if (! is_array($exit[$section])) {
                $errors[] = ['path' => "exit.{$section}", 'message' => "exit.{$section} must be an object"];

                continue;
            }
            self::checkRules($exit[$section]['rules'] ?? null, "exit.{$section}.rules", true, $errors);
            foreach ($exit[$section]['rules'] ?? [] as $i => $rule) {
                if (is_array($rule) && isset($rule['action']) && $rule['action'] !== 'close') {
                    $errors[] = ['path' => "exit.{$section}.rules[{$i}].action", 'message' => 'action must be "close" (v1)'];
                }
            }
        }
        if (isset($exit['time_stop']) && $exit['time_stop'] !== null) {
            if (! is_array($exit['time_stop'])) {
                $errors[] = ['path' => 'exit.time_stop', 'message' => 'exit.time_stop must be an object or null'];
            } else {
                foreach (['bars', 'hours'] as $k) {
                    if (isset($exit['time_stop'][$k]) && ! is_numeric($exit['time_stop'][$k])) {
                        $errors[] = ['path' => "exit.time_stop.{$k}", 'message' => "exit.time_stop.{$k} must be a number"];
                    }
                }
            }
        }
    }

    /** @param array<int, array{path: string, message: string}> $errors */
    private static function checkRisk(mixed $risk, array &$errors): void
    {
        if ($risk === null) {
            return;
        }
        if (! is_array($risk)) {
            $errors[] = ['path' => 'risk', 'message' => 'risk must be an object'];

            return;
        }
        if (isset($risk['max_positions']) && ! self::isPositiveInt($risk['max_positions'])) {
            $errors[] = ['path' => 'risk.max_positions', 'message' => 'risk.max_positions must be a positive integer'];
        }
        if (isset($risk['daily_loss_cap_pct']) && (! is_numeric($risk['daily_loss_cap_pct']) || $risk['daily_loss_cap_pct'] <= 0)) {
            $errors[] = ['path' => 'risk.daily_loss_cap_pct', 'message' => 'risk.daily_loss_cap_pct must be a positive number'];
        }
        if (isset($risk['leverage_cap']) && (! is_numeric($risk['leverage_cap']) || $risk['leverage_cap'] <= 0)) {
            $errors[] = ['path' => 'risk.leverage_cap', 'message' => 'risk.leverage_cap must be a positive number'];
        }
    }

    /** @param array<int, array{path: string, message: string}> $errors */
    private static function checkRuleSection(mixed $section, string $path, bool $allowPosition, array &$errors): void
    {
        if ($section === null) {
            return;
        }
        if (! is_array($section)) {
            $errors[] = ['path' => $path, 'message' => "{$path} must be an object"];

            return;
        }
        self::checkRules($section['rules'] ?? null, "{$path}.rules", $allowPosition, $errors);
    }

    /** @param array<int, array{path: string, message: string}> $errors */
    private static function checkRules(mixed $rules, string $path, bool $allowPosition, array &$errors): void
    {
        if ($rules === null) {
            return;
        }
        if (! is_array($rules)) {
            $errors[] = ['path' => $path, 'message' => "{$path} must be an array of rules"];

            return;
        }
        foreach ($rules as $i => $rule) {
            $rulePath = "{$path}[{$i}]";
            if (! is_array($rule)) {
                $errors[] = ['path' => $rulePath, 'message' => "{$rulePath} must be an object"];

                continue;
            }
            if (! isset($rule['field']) || ! is_string($rule['field']) || ! self::isAllowedField($rule['field'], $allowPosition)) {
                $errors[] = ['path' => "{$rulePath}.field", 'message' => 'field is unknown (stats key, indicators.*, time.*, or position.* where allowed)'];
            }
            if (isset($rule['tf'])) {
                if (! is_string($rule['tf']) || $rule['tf'] === '') {
                    $errors[] = ['path' => "{$rulePath}.tf", 'message' => 'tf must be a non-empty string'];
                } elseif (Candle::canonicalTimeframe($rule['tf']) === null) {
                    $errors[] = ['path' => "{$rulePath}.tf", 'message' => 'tf must be a known timeframe (e.g. 1m, 15m, 1h, 6h, 1d)'];
                }
            }
            $op = $rule['op'] ?? null;
            $isCrosses = $op === 'crosses_above' || $op === 'crosses_below';
            $value = $rule['value'] ?? null;
            // {value: {field: "..."}} compares against another field instead of a literal, for any op (docs/STRATEGY_SCHEMA_V2.md).
            $isFieldRef = is_array($value) && is_string($value['field'] ?? null);
            if (! in_array($op, JsonRuleEvaluator::OPS, true)) {
                $errors[] = ['path' => "{$rulePath}.op", 'message' => 'op must be one of: '.implode(', ', JsonRuleEvaluator::OPS)];
            } elseif (in_array($op, ['between', 'in', 'not_in'], true) && ! is_array($value)) {
                $errors[] = ['path' => "{$rulePath}.value", 'message' => "value must be an array for op \"{$op}\""];
            } elseif ($op === 'between' && is_array($value) && count($value) !== 2) {
                $errors[] = ['path' => "{$rulePath}.value", 'message' => 'value must have exactly 2 elements [min, max] for op "between"'];
            } elseif ($isCrosses && ! str_starts_with((string) ($rule['field'] ?? ''), 'ind.')) {
                $errors[] = ['path' => "{$rulePath}.field", 'message' => 'crosses_* needs an ind.* field on both sides'];
            } elseif ($isCrosses && ! is_numeric($value) && ! ($isFieldRef && str_starts_with($value['field'], 'ind.'))) {
                $errors[] = ['path' => "{$rulePath}.value", 'message' => 'crosses_* needs an ind.* field on both sides'];
            } elseif (! $isCrosses && ! $isFieldRef && is_numeric($value) && self::isBareObvField($rule['field'] ?? null)) {
                // ind.obv is a running total over a sliding lookback window: its absolute level drifts
                // as old bars age out, so a literal threshold can fire on nothing happening. crosses_*
                // and field-to-field comparisons share the same window and stay meaningful.
                $errors[] = ['path' => "{$rulePath}.value", 'message' => 'ind.obv drifts with the lookback window; compare it with crosses_above/crosses_below or against another field, not a literal'];
            }
            if ($isFieldRef && ! self::isAllowedField($value['field'], $allowPosition)) {
                $errors[] = ['path' => "{$rulePath}.value.field", 'message' => 'value.field is unknown (stats key, indicators.*, time.*, or position.* where allowed)'];
            }
            if (! array_key_exists('value', $rule)) {
                $errors[] = ['path' => "{$rulePath}.value", 'message' => 'value is required'];
            } elseif (! $isFieldRef && ! in_array($op, ['between', 'in', 'not_in'], true) && (is_array($value) || is_object($value))) {
                $errors[] = ['path' => "{$rulePath}.value", 'message' => 'value must be a scalar or null'];
            }
        }
    }

    /** True for `ind.obv` (or `ind.obv.value`) specifically — the one indicator whose raw level is not window-invariant. */
    private static function isBareObvField(mixed $field): bool
    {
        if (! is_string($field)) {
            return false;
        }
        try {
            return IndicatorField::parse($field)?->name === 'obv';
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    private static function isAllowedField(string $field, bool $allowPosition): bool
    {
        if (in_array($field, self::STAT_FIELDS, true) || in_array($field, self::TIME_FIELDS, true)) {
            return true;
        }
        if (str_starts_with($field, 'indicators.')) {
            return in_array(substr($field, strlen('indicators.')), self::INDICATORS, true);
        }
        if (str_starts_with($field, 'extra.indicators.')) {
            return in_array(substr($field, strlen('extra.indicators.')), self::INDICATORS, true);
        }
        if (str_starts_with($field, 'ind.')) {
            try {
                return IndicatorField::parse($field) !== null;
            } catch (\InvalidArgumentException) {
                return false;
            }
        }

        return $allowPosition && in_array($field, self::POSITION_FIELDS, true);
    }

    private static function isPositiveInt(mixed $v): bool
    {
        return is_int($v) && $v > 0;
    }
}
