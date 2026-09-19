<?php

declare(strict_types=1);

namespace App\Desk\Strategies;

use App\Models\Candle;

/**
 * Validates a strategy-plugin definition, dispatching on `schema_version`.
 *
 * v1 (see docs/STRATEGY_SCHEMA.md): sectioned meta/params/setup/trigger/
 * entry/management/exit/risk, every section optional except `meta` and
 * `entry`. Its checks are untouched by v2 — see validateV1().
 *
 * v2 (see docs/STRATEGY_SCHEMA_V2.md): signals/entry/adds/take_profit/
 * reentry/stop/risk. `params` are substituted (ParamSubstitutor) before any
 * other check runs, so a rule value or formula `expr` written as `$name`
 * validates as its default. ind.* fields are checked against a private
 * parser table here — phase A is writing IndicatorField in parallel; once
 * merged, validateIndField() should defer to IndicatorField::parse()
 * instead of duplicating the grammar.
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

    /** v2 adds these on top of v1's POSITION_FIELDS (docs/STRATEGY_SCHEMA_V2.md, "Field-to-field comparison and crosses"). */
    private const POSITION_FIELDS_V2 = [
        'position.pnl_pct', 'position.hold_hours', 'position.avg', 'position.peak_pct',
        'position.rungs_fired', 'position.reentries', 'position.adds_count', 'position.trims_count',
    ];

    private const CROSSES_OPS = ['crosses_above', 'crosses_below'];

    private const SIZING_MODES = ['pct_equity', 'usd', 'kelly', 'formula'];

    /**
     * Local parser table for `ind.<name>(<args>)[.<output>]`, matching the
     * spec's indicator table. `bare` = the field is valid with no `.output`
     * suffix (the "value" output). `arity` = required numeric argument count.
     */
    private const IND_TABLE = [
        'rsi' => ['arity' => 1, 'bare' => true, 'outputs' => [], 'args' => ['period']],
        'sma' => ['arity' => 1, 'bare' => true, 'outputs' => [], 'args' => ['period']],
        'ema' => ['arity' => 1, 'bare' => true, 'outputs' => [], 'args' => ['period']],
        'atr' => ['arity' => 1, 'bare' => true, 'outputs' => ['pct'], 'args' => ['period']],
        'adx' => ['arity' => 1, 'bare' => true, 'outputs' => [], 'args' => ['period']],
        'macd' => ['arity' => 3, 'bare' => false, 'outputs' => ['macd', 'signal', 'hist'], 'args' => ['period', 'period', 'period']],
        'bb' => ['arity' => 2, 'bare' => false, 'outputs' => ['upper', 'lower', 'mid', 'pos'], 'args' => ['period', 'multiplier']],
        'vwap' => ['arity' => 0, 'bare' => true, 'outputs' => [], 'args' => []],
        'obv' => ['arity' => 0, 'bare' => true, 'outputs' => [], 'args' => []],
        'smx' => ['arity' => 0, 'bare' => false, 'outputs' => ['wt1', 'wt2', 'wt_cross', 'rsi_mfi', 'buy', 'sell', 'gold_buy', 'div_bull', 'div_bear'], 'args' => []],
    ];

    /** Lowercased; matched case-insensitively since meta.timeframe is written lowercase ("1h") while
     * CoinbaseMarketData::GRANULARITY_MAP keys are uppercase ("1H") — same set, both are seen. */
    private const KNOWN_TIMEFRAMES = ['1m', '5m', '15m', '30m', '1h', '6h', '1d'];

    /** @return array{valid: bool, errors: array<int, array{path: string, message: string}>, warnings: array<int, array{path: string, message: string}>} */
    public static function validate(mixed $definition): array
    {
        if (! is_array($definition)) {
            return ['valid' => false, 'errors' => [['path' => '$', 'message' => 'definition must be a JSON object']], 'warnings' => []];
        }

        return match ($definition['schema_version'] ?? null) {
            2 => self::validateV2($definition),
            default => self::validateV1($definition),
        };
    }

    /** @return array{valid: bool, errors: array<int, array{path: string, message: string}>, warnings: array<int, array{path: string, message: string}>} */
    private static function validateV1(array $definition): array
    {
        $errors = [];

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

        return ['valid' => $errors === [], 'errors' => $errors, 'warnings' => []];
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

    // ── v2 (docs/STRATEGY_SCHEMA_V2.md) ─────────────────────────────────

    /** @return array{valid: bool, errors: array<int, array{path: string, message: string}>, warnings: array<int, array{path: string, message: string}>} */
    private static function validateV2(array $definition): array
    {
        $definition = ParamSubstitutor::apply($definition);
        $errors = [];

        if (($definition['schema_version'] ?? null) !== 2) {
            $errors[] = ['path' => 'schema_version', 'message' => 'schema_version must be 2'];
        }
        if (! isset($definition['key']) || ! is_string($definition['key']) || ! preg_match('/^[a-z0-9-]{2,64}$/', $definition['key'])) {
            $errors[] = ['path' => 'key', 'message' => 'key is required: 2-64 chars, lowercase letters, numbers, dashes'];
        }
        if (isset($definition['base']) && ! in_array($definition['base'], self::BASES, true)) {
            $errors[] = ['path' => 'base', 'message' => 'base must be one of: '.implode(', ', self::BASES)];
        }

        self::checkMeta($definition['meta'] ?? null, $errors);
        self::checkParams($definition['params'] ?? null, $errors);

        $groups = [];
        $signalNames = self::checkSignals($definition['signals'] ?? null, $errors, $groups);
        self::checkEntryV2($definition['entry'] ?? null, $signalNames, $errors);
        // position.* is fine in a signal used by reentry.when/stop (a position already exists
        // there); it's only meaningless in entry.when, since scan runs with no position yet —
        // checked here, against entry.when specifically, rather than at signal declaration.
        $entryWhen = is_array($definition['entry'] ?? null) ? ($definition['entry']['when'] ?? null) : null;
        self::checkNoPositionFieldsInScanSignals($entryWhen, $groups, $errors);
        self::checkAddsV2($definition['adds'] ?? null, $errors);
        self::checkTakeProfitV2($definition['take_profit'] ?? null, $errors);
        self::checkReentryV2($definition['reentry'] ?? null, $definition['take_profit'] ?? null, $signalNames, $errors);
        self::checkStopV2($definition['stop'] ?? null, $errors);
        self::checkRisk($definition['risk'] ?? null, $errors);

        $warnings = [];
        foreach (array_diff($signalNames, self::referencedSignalNames($definition)) as $unreferenced) {
            $warnings[] = ['path' => "signals.{$unreferenced}", 'message' => 'signal is never referenced'];
        }

        return ['valid' => $errors === [], 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Every signal name referenced from entry.when or reentry.when (including "entry" meaning
     * "reuse entry.when"), for the "signal is never referenced" warning. Non-array/malformed
     * `when` values are ignored here — checkEntryV2/checkReentryV2 already error on those.
     *
     * @return array<int, string>
     */
    private static function referencedSignalNames(array $definition): array
    {
        $entryWhen = is_array($definition['entry'] ?? null) && is_array($definition['entry']['when'] ?? null)
            ? array_filter($definition['entry']['when'], 'is_string')
            : [];

        $reentryWhen = is_array($definition['reentry'] ?? null) ? ($definition['reentry']['when'] ?? null) : null;
        $reentryNames = match (true) {
            $reentryWhen === 'entry' => $entryWhen,
            is_array($reentryWhen) => array_filter($reentryWhen, 'is_string'),
            default => [],
        };

        return [...$entryWhen, ...$reentryNames];
    }

    /**
     * @param  array<int, array{path: string, message: string}>  $errors
     * @param  array<string, array<string, mixed>>  $groups  output: name => raw {all, any} group, for
     *                                                        checkNoPositionFieldsInScanSignals()
     * @return array<int, string> every declared signal name, for entry.when / reentry.when existence checks
     */
    private static function checkSignals(mixed $signals, array &$errors, array &$groups = []): array
    {
        if ($signals === null) {
            return [];
        }
        if (! is_array($signals)) {
            $errors[] = ['path' => 'signals', 'message' => 'signals must be an object'];

            return [];
        }
        $names = [];
        foreach ($signals as $name => $group) {
            $path = "signals.{$name}";
            if (! is_string($name) || $name === 'entry' || ! preg_match('/^[a-z][a-z0-9_]{1,31}$/', $name)) {
                $errors[] = ['path' => $path, 'message' => 'signal name must match ^[a-z][a-z0-9_]{1,31}$ and not be "entry" (reserved)'];

                continue;
            }
            $names[] = $name;
            if (! is_array($group)) {
                $errors[] = ['path' => $path, 'message' => "{$path} must be an object with 'all' and/or 'any'"];

                continue;
            }
            $groups[$name] = $group;
            if (! array_key_exists('all', $group) && ! array_key_exists('any', $group)) {
                $errors[] = ['path' => $path, 'message' => "{$path} requires 'all' and/or 'any'"];
            }
            foreach (['all', 'any'] as $k) {
                if (! array_key_exists($k, $group)) {
                    continue;
                }
                if (! is_array($group[$k])) {
                    $errors[] = ['path' => "{$path}.{$k}", 'message' => "{$path}.{$k} must be an array of rules"];

                    continue;
                }
                foreach ($group[$k] as $i => $rule) {
                    // Permissive here (allowPosition: true): a signal is just a name until
                    // something references it, and position.* is legal from reentry.when/stop.
                    // checkNoPositionFieldsInScanSignals() re-checks the entry.when subset.
                    self::checkRuleV2($rule, "{$path}.{$k}[{$i}]", true, $errors);
                }
            }
        }

        return $names;
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @param  array<int, array{path: string, message: string}>  $errors
     */
    private static function checkNoPositionFieldsInScanSignals(mixed $when, array $groups, array &$errors): void
    {
        if (! is_array($when)) {
            return;
        }
        foreach ($when as $name) {
            if (! is_string($name) || ! isset($groups[$name])) {
                continue;
            }
            foreach (['all', 'any'] as $k) {
                $rules = $groups[$name][$k] ?? null;
                if (! is_array($rules)) {
                    continue;
                }
                foreach ($rules as $i => $rule) {
                    if (! is_array($rule)) {
                        continue;
                    }
                    $path = "signals.{$name}.{$k}[{$i}]";
                    if (is_string($rule['field'] ?? null) && str_starts_with($rule['field'], 'position.')) {
                        $errors[] = ['path' => "{$path}.field", 'message' => 'position.* fields are not allowed here'];
                    }
                    $value = $rule['value'] ?? null;
                    if (is_array($value) && is_string($value['field'] ?? null) && str_starts_with($value['field'], 'position.')) {
                        $errors[] = ['path' => "{$path}.value.field", 'message' => 'position.* fields are not allowed here'];
                    }
                }
            }
        }
    }

    /**
     * @param  array<int, string>  $signalNames
     * @param  array<int, array{path: string, message: string}>  $errors
     */
    private static function checkEntryV2(mixed $entry, array $signalNames, array &$errors): void
    {
        if (! is_array($entry)) {
            $errors[] = ['path' => 'entry', 'message' => 'entry is required and must be an object'];

            return;
        }
        if (isset($entry['side']) && ! in_array($entry['side'], self::SIDES, true)) {
            $errors[] = ['path' => 'entry.side', 'message' => 'entry.side must be one of: '.implode(', ', self::SIDES)];
        }
        if (! isset($entry['when']) || ! is_array($entry['when']) || $entry['when'] === []) {
            $errors[] = ['path' => 'entry.when', 'message' => 'entry.when is required: at least one signal name'];
        } else {
            self::checkSignalRefs($entry['when'], 'entry.when', $signalNames, $errors);
        }
        if (isset($entry['max_candidates']) && ! self::isPositiveInt($entry['max_candidates'])) {
            $errors[] = ['path' => 'entry.max_candidates', 'message' => 'entry.max_candidates must be a positive integer'];
        }
        self::checkRuleListV2($entry['confirm'] ?? null, 'entry.confirm', false, $errors);
        self::checkSizingObject($entry['size'] ?? null, 'entry.size', 'entry', $errors, true);
    }

    /** @param array<int, array{path: string, message: string}> $errors */
    private static function checkAddsV2(mixed $adds, array &$errors): void
    {
        if ($adds === null) {
            return;
        }
        if (! is_array($adds)) {
            $errors[] = ['path' => 'adds', 'message' => 'adds must be an array'];

            return;
        }
        foreach ($adds as $i => $rung) {
            $path = "adds[{$i}]";
            if (! is_array($rung)) {
                $errors[] = ['path' => $path, 'message' => "{$path} must be an object"];

                continue;
            }
            if (! isset($rung['trigger']) || ! is_array($rung['trigger'])) {
                $errors[] = ['path' => "{$path}.trigger", 'message' => 'trigger is required: {field, op, value}'];
            } else {
                self::checkRuleV2($rung['trigger'], "{$path}.trigger", true, $errors);
            }
            $hasSizePct = array_key_exists('size_pct', $rung);
            $hasSize = array_key_exists('size', $rung);
            if (! $hasSizePct && ! $hasSize) {
                $errors[] = ['path' => "{$path}.size_pct", 'message' => 'size_pct or size is required'];
            }
            if ($hasSizePct) {
                $name = self::unresolvedParamName($rung['size_pct']);
                if ($name !== null) {
                    $errors[] = ['path' => "{$path}.size_pct", 'message' => "unknown parameter reference \${$name}"];
                } elseif (! is_numeric($rung['size_pct']) || $rung['size_pct'] <= 0) {
                    $errors[] = ['path' => "{$path}.size_pct", 'message' => 'size_pct must be a positive number (percent of initial cost)'];
                }
            }
            if ($hasSize) {
                self::checkSizingObject($rung['size'], "{$path}.size", 'adds', $errors, false);
            }
            if ($hasSizePct && $hasSize) {
                $errors[] = ['path' => "{$path}.size", 'message' => 'adds[i] takes size_pct or size, not both'];
            }
        }
    }

    /** @param array<int, array{path: string, message: string}> $errors */
    private static function checkTakeProfitV2(mixed $tp, array &$errors): void
    {
        if ($tp === null) {
            return;
        }
        if (! is_array($tp)) {
            $errors[] = ['path' => 'take_profit', 'message' => 'take_profit must be an object'];

            return;
        }
        if (isset($tp['from']) && $tp['from'] !== 'avg') {
            $errors[] = ['path' => 'take_profit.from', 'message' => 'from must be "avg"'];
        }
        if (isset($tp['ladder'])) {
            if (! is_array($tp['ladder'])) {
                $errors[] = ['path' => 'take_profit.ladder', 'message' => 'ladder must be an array'];
            } else {
                $sum = 0.0;
                $prevPct = null;
                foreach ($tp['ladder'] as $i => $rung) {
                    $path = "take_profit.ladder[{$i}]";
                    if (! is_array($rung)) {
                        $errors[] = ['path' => $path, 'message' => "{$path} must be an object"];

                        continue;
                    }
                    $atPct = $rung['at_pct'] ?? null;
                    if (! is_numeric($atPct) || $atPct <= 0) {
                        $errors[] = ['path' => "{$path}.at_pct", 'message' => 'at_pct is required and must be a positive number'];
                    } else {
                        if ($prevPct !== null && $atPct <= $prevPct) {
                            $errors[] = ['path' => "{$path}.at_pct", 'message' => 'at_pct must strictly increase along the ladder'];
                        }
                        $prevPct = $atPct;
                    }
                    $sellPct = $rung['sell_pct_of_original'] ?? null;
                    if (! is_numeric($sellPct) || $sellPct <= 0) {
                        $errors[] = ['path' => "{$path}.sell_pct_of_original", 'message' => 'sell_pct_of_original is required and must be a positive number'];
                    } else {
                        $sum += $sellPct;
                    }
                }
                if ($sum > 100) {
                    $errors[] = ['path' => 'take_profit.ladder', 'message' => 'sum of sell_pct_of_original must be <= 100'];
                }
            }
        }
        if (isset($tp['runner']) && $tp['runner'] !== null) {
            $ttp = is_array($tp['runner']) ? ($tp['runner']['ttp'] ?? null) : null;
            if (! is_array($ttp)) {
                $errors[] = ['path' => 'take_profit.runner.ttp', 'message' => 'runner.ttp is required: {activate_pct?, giveback_pct}'];
            } else {
                if (isset($ttp['activate_pct']) && ! is_numeric($ttp['activate_pct'])) {
                    $errors[] = ['path' => 'take_profit.runner.ttp.activate_pct', 'message' => 'activate_pct must be a number'];
                }
                if (! isset($ttp['giveback_pct']) || ! is_numeric($ttp['giveback_pct']) || $ttp['giveback_pct'] <= 0) {
                    $errors[] = ['path' => 'take_profit.runner.ttp.giveback_pct', 'message' => 'giveback_pct is required and must be a positive number'];
                }
            }
        }
        if (isset($tp['reset_on_add']) && ! is_bool($tp['reset_on_add'])) {
            $errors[] = ['path' => 'take_profit.reset_on_add', 'message' => 'reset_on_add must be a boolean'];
        }
        self::checkRuleListV2($tp['rules'] ?? null, 'take_profit.rules', true, $errors);
        foreach ($tp['rules'] ?? [] as $i => $rule) {
            if (is_array($rule) && isset($rule['action']) && $rule['action'] !== 'close') {
                $errors[] = ['path' => "take_profit.rules[{$i}].action", 'message' => 'action must be "close"'];
            }
        }
    }

    /**
     * @param  array<int, string>  $signalNames
     * @param  array<int, array{path: string, message: string}>  $errors
     */
    private static function checkReentryV2(mixed $reentry, mixed $takeProfit, array $signalNames, array &$errors): void
    {
        if ($reentry === null) {
            return;
        }
        if (! is_array($reentry)) {
            $errors[] = ['path' => 'reentry', 'message' => 'reentry must be an object'];

            return;
        }
        $ladder = is_array($takeProfit) && is_array($takeProfit['ladder'] ?? null) ? $takeProfit['ladder'] : [];
        if ($ladder === []) {
            $errors[] = ['path' => 'reentry', 'message' => 'reentry requires a non-empty take_profit.ladder (nothing to re-buy otherwise)'];
        }
        if (array_key_exists('when', $reentry) && $reentry['when'] !== null && $reentry['when'] !== 'entry') {
            self::checkSignalRefs($reentry['when'], 'reentry.when', $signalNames, $errors, '"entry", an array of signal names, or null');
        }
        if (isset($reentry['after_rungs']) && ! self::isPositiveInt($reentry['after_rungs'])) {
            $errors[] = ['path' => 'reentry.after_rungs', 'message' => 'after_rungs must be a positive integer'];
        }
        if (! is_array($reentry['retrace'] ?? null)) {
            $errors[] = ['path' => 'reentry.retrace', 'message' => 'retrace is required: {of, min, stay_above_avg?}'];
        } else {
            $retrace = $reentry['retrace'];
            if (! in_array($retrace['of'] ?? null, ['rung_spacing', 'pct'], true)) {
                $errors[] = ['path' => 'reentry.retrace.of', 'message' => 'of must be "rung_spacing" or "pct"'];
            }
            $name = self::unresolvedParamName($retrace['min'] ?? null);
            if ($name !== null) {
                $errors[] = ['path' => 'reentry.retrace.min', 'message' => "unknown parameter reference \${$name}"];
            } elseif (! isset($retrace['min']) || ! is_numeric($retrace['min']) || $retrace['min'] <= 0) {
                $errors[] = ['path' => 'reentry.retrace.min', 'message' => 'min is required and must be a positive number'];
            }
            if (isset($retrace['stay_above_avg']) && ! is_bool($retrace['stay_above_avg'])) {
                $errors[] = ['path' => 'reentry.retrace.stay_above_avg', 'message' => 'stay_above_avg must be a boolean'];
            }
        }
        self::checkSizingObject($reentry['size'] ?? null, 'reentry.size', 'reentry', $errors, true);
        if (isset($reentry['min_spacing_x_fees']) && (! is_numeric($reentry['min_spacing_x_fees']) || $reentry['min_spacing_x_fees'] < 0)) {
            $errors[] = ['path' => 'reentry.min_spacing_x_fees', 'message' => 'min_spacing_x_fees must be a non-negative number'];
        }
        if (isset($reentry['cash_out'])) {
            if (! is_array($reentry['cash_out'])) {
                $errors[] = ['path' => 'reentry.cash_out', 'message' => 'cash_out must be an object'];
            } else {
                $co = $reentry['cash_out'];
                if (isset($co['when']) && $co['when'] !== 'green_after_fees') {
                    $errors[] = ['path' => 'reentry.cash_out.when', 'message' => 'when must be "green_after_fees"'];
                }
                if (isset($co['sell_pct_of_reentry']) && (! is_numeric($co['sell_pct_of_reentry']) || $co['sell_pct_of_reentry'] <= 0 || $co['sell_pct_of_reentry'] > 100)) {
                    $errors[] = ['path' => 'reentry.cash_out.sell_pct_of_reentry', 'message' => 'sell_pct_of_reentry must be a number in (0, 100]'];
                }
                if (isset($co['remainder']) && ! in_array($co['remainder'], ['runner', 'ladder'], true)) {
                    $errors[] = ['path' => 'reentry.cash_out.remainder', 'message' => 'remainder must be "runner" or "ladder"'];
                }
            }
        }
        if (isset($reentry['max_per_position']) && ! self::isPositiveInt($reentry['max_per_position'])) {
            $errors[] = ['path' => 'reentry.max_per_position', 'message' => 'max_per_position must be a positive integer'];
        }
    }

    /** @param array<int, array{path: string, message: string}> $errors */
    private static function checkStopV2(mixed $stop, array &$errors): void
    {
        if ($stop === null) {
            return;
        }
        if (! is_array($stop)) {
            $errors[] = ['path' => 'stop', 'message' => 'stop must be an object'];

            return;
        }
        if (isset($stop['pct_from_avg'])) {
            $name = self::unresolvedParamName($stop['pct_from_avg']);
            if ($name !== null) {
                $errors[] = ['path' => 'stop.pct_from_avg', 'message' => "unknown parameter reference \${$name}"];
            } elseif (! is_numeric($stop['pct_from_avg']) || $stop['pct_from_avg'] <= 0) {
                $errors[] = ['path' => 'stop.pct_from_avg', 'message' => 'pct_from_avg must be a positive number'];
            }
        }
        if (isset($stop['anchor']) && ! in_array($stop['anchor'], ['avg', 'entry'], true)) {
            $errors[] = ['path' => 'stop.anchor', 'message' => 'anchor must be "avg" or "entry"'];
        }
        if (array_key_exists('time_hours', $stop) && $stop['time_hours'] !== null && ! is_numeric($stop['time_hours'])) {
            $errors[] = ['path' => 'stop.time_hours', 'message' => 'time_hours must be a number or null'];
        }
        self::checkRuleListV2($stop['rules'] ?? null, 'stop.rules', true, $errors);
        foreach ($stop['rules'] ?? [] as $i => $rule) {
            if (is_array($rule) && isset($rule['action']) && $rule['action'] !== 'close') {
                $errors[] = ['path' => "stop.rules[{$i}].action", 'message' => 'action must be "close"'];
            }
        }
    }

    /**
     * A sizing object used by entry.size / adds[].size / reentry.size
     * (docs/STRATEGY_SCHEMA_V2.md, "Sizing objects").
     *
     * @param  array<int, array{path: string, message: string}>  $errors
     */
    private static function checkSizingObject(mixed $sizing, string $path, string $section, array &$errors, bool $required): void
    {
        if ($sizing === null) {
            if ($required) {
                $errors[] = ['path' => $path, 'message' => "{$path} is required (a sizing object)"];
            }

            return;
        }
        if (! is_array($sizing)) {
            $errors[] = ['path' => $path, 'message' => "{$path} must be an object"];

            return;
        }
        $mode = $sizing['mode'] ?? null;
        if (! in_array($mode, self::SIZING_MODES, true)) {
            $errors[] = ['path' => "{$path}.mode", 'message' => 'mode must be one of: '.implode(', ', self::SIZING_MODES)];

            return;
        }
        switch ($mode) {
            case 'pct_equity':
            case 'usd':
                $value = $sizing['value'] ?? null;
                $name = self::unresolvedParamName($value);
                if ($name !== null) {
                    $errors[] = ['path' => "{$path}.value", 'message' => "unknown parameter reference \${$name}"];
                } elseif (! is_numeric($value) || $value <= 0) {
                    $errors[] = ['path' => "{$path}.value", 'message' => 'value is required and must be a positive number'];
                }
                break;
            case 'kelly':
                $name = self::unresolvedParamName($sizing['fraction'] ?? null);
                if ($name !== null) {
                    $errors[] = ['path' => "{$path}.fraction", 'message' => "unknown parameter reference \${$name}"];
                } elseif (! isset($sizing['fraction']) || ! is_numeric($sizing['fraction']) || $sizing['fraction'] <= 0) {
                    $errors[] = ['path' => "{$path}.fraction", 'message' => 'fraction is required and must be a positive number'];
                }
                if (isset($sizing['max_pct_book']) && ! is_numeric($sizing['max_pct_book'])) {
                    $errors[] = ['path' => "{$path}.max_pct_book", 'message' => 'max_pct_book must be a number'];
                }
                break;
            case 'formula':
                self::checkFormula($sizing['expr'] ?? null, "{$path}.expr", $section, $errors);
                break;
        }
    }

    /** @param array<int, array{path: string, message: string}> $errors */
    private static function checkFormula(mixed $expr, string $path, string $section, array &$errors): void
    {
        if (! is_string($expr) || trim($expr) === '') {
            $errors[] = ['path' => $path, 'message' => 'expr is required and must be a non-empty string'];

            return;
        }
        if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)/', $expr, $m) > 0) {
            foreach (array_unique($m[1]) as $name) {
                $errors[] = ['path' => $path, 'message' => "unknown parameter reference \${$name}"];
            }

            return;
        }
        try {
            $ast = Formula::parse($expr);
        } catch (FormulaParseError $e) {
            $errors[] = ['path' => $path, 'message' => "formula parse error at offset {$e->offset}: {$e->getMessage()}"];

            return;
        }
        $allowed = Formula::allowedVariables($section);
        foreach (Formula::variablesUsed($ast) as $v) {
            if (! in_array($v['name'], $allowed, true)) {
                $errors[] = ['path' => $path, 'message' => "unknown variable \"{$v['name']}\" in {$section}; allowed: ".implode(', ', $allowed)];
            }
        }
    }

    /**
     * @param  array<int, string>  $signalNames
     * @param  array<int, array{path: string, message: string}>  $errors
     */
    private static function checkSignalRefs(mixed $when, string $path, array $signalNames, array &$errors, string $shapeHint = 'an array of signal names'): void
    {
        if ($when === null) {
            return;
        }
        if (! is_array($when) || array_any($when, fn ($s) => ! is_string($s))) {
            $errors[] = ['path' => $path, 'message' => "{$path} must be {$shapeHint}"];

            return;
        }
        foreach ($when as $i => $name) {
            if (! in_array($name, $signalNames, true)) {
                $errors[] = ['path' => "{$path}[{$i}]", 'message' => "unknown signal \"{$name}\""];
            }
        }
    }

    /** @param array<int, array{path: string, message: string}> $errors */
    private static function checkRuleListV2(mixed $rules, string $path, bool $allowPosition, array &$errors): void
    {
        if ($rules === null) {
            return;
        }
        if (! is_array($rules)) {
            $errors[] = ['path' => $path, 'message' => "{$path} must be an array of rules"];

            return;
        }
        foreach ($rules as $i => $rule) {
            self::checkRuleV2($rule, "{$path}[{$i}]", $allowPosition, $errors);
        }
    }

    /** One `{field, op, value}` rule under the v2 grammar (v1 ops + crosses_*, ind.* fields, field-ref values). */
    private static function checkRuleV2(mixed $rule, string $path, bool $allowPosition, array &$errors): void
    {
        if (! is_array($rule)) {
            $errors[] = ['path' => $path, 'message' => "{$path} must be an object"];

            return;
        }
        if (! isset($rule['field']) || ! is_string($rule['field'])) {
            $errors[] = ['path' => "{$path}.field", 'message' => 'field is required and must be a string'];
        } else {
            $msg = self::validateFieldV2($rule['field'], $allowPosition);
            if ($msg !== null) {
                $errors[] = ['path' => "{$path}.field", 'message' => $msg];
            }
        }
        if (isset($rule['tf']) && (! is_string($rule['tf']) || ! in_array(strtolower($rule['tf']), self::KNOWN_TIMEFRAMES, true))) {
            $errors[] = ['path' => "{$path}.tf", 'message' => 'tf must be one of: '.implode(', ', self::KNOWN_TIMEFRAMES)];
        }

        $opsV2 = [...JsonRuleEvaluator::OPS, ...self::CROSSES_OPS];
        $op = $rule['op'] ?? null;
        if (! in_array($op, $opsV2, true)) {
            $errors[] = ['path' => "{$path}.op", 'message' => 'op must be one of: '.implode(', ', $opsV2)];
            $op = null;
        }

        if (! array_key_exists('value', $rule)) {
            $errors[] = ['path' => "{$path}.value", 'message' => 'value is required'];

            return;
        }
        $value = $rule['value'];

        if (in_array($op, self::CROSSES_OPS, true)) {
            if (! is_array($value) || array_keys($value) !== ['field'] || ! is_string($value['field'] ?? null)) {
                $errors[] = ['path' => "{$path}.value", 'message' => "value must be {field: \"...\"} for op \"{$op}\""];
            } else {
                $msg = self::validateFieldV2($value['field'], $allowPosition);
                if ($msg !== null) {
                    $errors[] = ['path' => "{$path}.value.field", 'message' => $msg];
                }
            }

            return;
        }
        if (is_array($value) && array_keys($value) === ['field']) {
            if (! is_string($value['field'])) {
                $errors[] = ['path' => "{$path}.value.field", 'message' => 'field must be a string'];
            } else {
                $msg = self::validateFieldV2($value['field'], $allowPosition);
                if ($msg !== null) {
                    $errors[] = ['path' => "{$path}.value.field", 'message' => $msg];
                }
            }

            return;
        }
        if (in_array($op, ['between', 'in', 'not_in'], true)) {
            if (! is_array($value)) {
                $errors[] = ['path' => "{$path}.value", 'message' => "value must be an array for op \"{$op}\""];
            } else {
                if ($op === 'between' && count($value) !== 2) {
                    $errors[] = ['path' => "{$path}.value", 'message' => 'value must have exactly 2 elements [min, max] for op "between"'];
                }
                foreach ($value as $i => $el) {
                    $name = self::unresolvedParamName($el);
                    if ($name !== null) {
                        $errors[] = ['path' => "{$path}.value[{$i}]", 'message' => "unknown parameter reference \${$name}"];
                    }
                }
            }

            return;
        }
        $name = self::unresolvedParamName($value);
        if ($name !== null) {
            $errors[] = ['path' => "{$path}.value", 'message' => "unknown parameter reference \${$name}"];

            return;
        }
        if (is_array($value) || is_object($value)) {
            $errors[] = ['path' => "{$path}.value", 'message' => 'value must be a scalar, null, or {field: "..."}'];
        }
    }

    private static function validateFieldV2(string $field, bool $allowPosition): ?string
    {
        if (in_array($field, self::STAT_FIELDS, true) || in_array($field, self::TIME_FIELDS, true)) {
            return null;
        }
        if (str_starts_with($field, 'position.')) {
            if (! $allowPosition) {
                return 'position.* fields are not allowed here';
            }

            return in_array($field, self::POSITION_FIELDS_V2, true) ? null : 'unknown position field';
        }
        if (str_starts_with($field, 'indicators.')) {
            return in_array(substr($field, strlen('indicators.')), self::INDICATORS, true) ? null : 'unknown v1 indicator alias';
        }
        if (str_starts_with($field, 'extra.indicators.')) {
            return in_array(substr($field, strlen('extra.indicators.')), self::INDICATORS, true) ? null : 'unknown v1 indicator alias';
        }
        if (str_starts_with($field, 'ind.')) {
            return self::validateIndField(substr($field, strlen('ind.')));
        }

        return 'field is unknown (stats key, ind.*, indicators.*, time.*, or position.* where allowed)';
    }

    /**
     * Parses `<name>(<args>)[.<output>]` (the part after `ind.`) against
     * IND_TABLE. NOTE: once phase A's IndicatorField lands, this should
     * delegate to IndicatorField::parse() instead of its own regex table.
     */
    private static function validateIndField(string $rest): ?string
    {
        if (! preg_match('/^([a-z_]+)(?:\(([^()]*)\))?(?:\.([a-z_]+))?$/', $rest, $m)) {
            return "malformed ind.* field \"ind.{$rest}\"";
        }
        $name = $m[1];
        $argsStr = $m[2] ?? null;
        $output = $m[3] ?? null;
        if (! isset(self::IND_TABLE[$name])) {
            return "unknown indicator \"ind.{$name}\"";
        }
        $spec = self::IND_TABLE[$name];
        if ($spec['arity'] > 0) {
            if ($argsStr === null || trim($argsStr) === '') {
                return "ind.{$name} requires {$spec['arity']} argument(s)";
            }
            $args = array_map('trim', explode(',', $argsStr));
            if (count($args) !== $spec['arity']) {
                return "ind.{$name} takes {$spec['arity']} argument(s), got ".count($args);
            }
            foreach ($args as $i => $a) {
                $role = $spec['args'][$i] ?? 'period';
                if ($role === 'multiplier') {
                    if (! preg_match('/^\d+(\.\d+)?$/', $a) || (float) $a <= 0) {
                        return "ind.{$name} multiplier arguments must be a positive number";
                    }
                } elseif (! preg_match('/^\d+$/', $a) || (int) $a < 2) {
                    return "ind.{$name} period arguments must be integers >= 2";
                }
            }
        } elseif ($argsStr !== null && trim($argsStr) !== '') {
            return "ind.{$name} takes no arguments";
        }
        if ($output === null || $output === '') {
            if (! $spec['bare']) {
                return "ind.{$name} requires an output, one of: .".implode(', .', $spec['outputs']);
            }
        } elseif (! in_array($output, $spec['outputs'], true)) {
            return "unknown output \".{$output}\" for ind.{$name}";
        }

        return null;
    }

    /** A rule value / formula token like "$fail_safe_pct" that ParamSubstitutor left unresolved because the name isn't in `params`. */
    private static function unresolvedParamName(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)/', $value, $m) === 1 ? $m[1] : null;
    }
}
