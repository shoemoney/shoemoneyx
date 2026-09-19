<?php

declare(strict_types=1);

namespace App\Desk\Strategies;

/**
 * Bridges the strategy-plugin definition shapes.
 *
 * - Legacy (no `schema_version` key): the flat `scan` / `vet` / `size` / `risk`
 *   shape documented in docs/STRATEGY_PLUGIN.md — every plugin saved before
 *   the formal schema landed.
 * - Canonical v1 (`schema_version: 1`): the sectioned `meta` / `params` /
 *   `setup` / `trigger` / `entry` / `management` / `exit` / `risk` shape
 *   documented in docs/STRATEGY_SCHEMA.md.
 * - Canonical v2 (`schema_version: 2`): `signals` / `entry` / `adds` /
 *   `take_profit` / `reentry` / `stop` / `risk`, documented in
 *   docs/STRATEGY_SCHEMA_V2.md, "Migration v1 → v2". Phase C
 *   (JsonPluginStrategy) is the engine that runs it.
 *
 * `migrate()` is what JsonPluginStrategy executes against: an already-versioned
 * definition (v1 or v2) passes through unchanged; a legacy flat shape (no
 * `schema_version` at all) maps forward to canonical v1. `toLegacyView()` is
 * the inverse, used by the Markdown/Pine exporters so they keep reading the
 * flat shape regardless of which shape the plugin was authored in (v2 goes
 * through v2ToV1View() first).
 */
final class SchemaMigrator
{
    public const CURRENT_VERSION = 1;

    public const V2_VERSION = 2;

    /**
     * The one dispatch every save path (StrategyPluginController::store/validate,
     * StrategySync::import, StrategyJsonTool::run) runs a definition through.
     *
     * @return array{valid: bool, errors: array<int, array{path: string, message: string}>}
     */
    public static function validateForSave(array $definition): array
    {
        return isset($definition['schema_version'])
            ? StrategySchemaValidator::validate($definition)
            : JsonPluginValidator::validate($definition);
    }

    /** @return array<string, mixed> */
    public static function migrate(array $definition): array
    {
        if (isset($definition['schema_version'])) {
            return $definition;
        }

        $scan = is_array($definition['scan'] ?? null) ? $definition['scan'] : [];
        $vet = is_array($definition['vet'] ?? null) ? $definition['vet'] : [];
        $size = is_array($definition['size'] ?? null) ? $definition['size'] : [];
        $risk = is_array($definition['risk'] ?? null) ? $definition['risk'] : [];

        $out = $definition;
        $out['schema_version'] = self::CURRENT_VERSION;
        $out['meta'] = [
            'name' => $definition['name'] ?? null,
            'description' => $definition['description'] ?? null,
            'tags' => [],
            'timeframe' => null,
            'assets' => [],
        ];
        $out['setup'] = ['rules' => []];
        $out['trigger'] = [
            'max_candidates' => $scan['max_candidates'] ?? null,
            'rules' => $scan['filters'] ?? [],
        ];
        $out['entry'] = [
            'side' => 'long',
            'sizing' => [
                'kelly_fraction' => $size['kelly_fraction'] ?? null,
                'max_pct_book' => $size['max_pct_book'] ?? null,
            ],
            'confirm' => $vet['rules'] ?? [],
        ];
        $out['management'] = ['adds' => [], 'trailing' => null, 'partials' => []];
        $out['exit'] = [
            'stop' => ['rules' => $risk['rules'] ?? []],
            'take_profit' => ['rules' => []],
            'time_stop' => null,
        ];
        $out['risk'] = [
            'max_positions' => null,
            'daily_loss_cap_pct' => null,
            'leverage_cap' => null,
        ];

        unset($out['scan'], $out['vet'], $out['size']);

        return $out;
    }

    /**
     * v1 `management.partials[] {pct, fraction}` (a fraction of whatever
     * remains) to v2 `take_profit.ladder[] {at_pct, sell_pct_of_original}`
     * (a percent of the position at the moment the ladder was armed).
     *
     * Exact per docs/STRATEGY_SCHEMA_V2.md, "Migration v1 → v2":
     * sell_pct_of_original_i = f_i × Π(1 − f_j, j<i) × 100. Pairing this
     * with `reset_on_add: false` is what makes the conversion produce
     * byte-identical fills to the v1 ladder — see the class docblock.
     *
     * @param  array<int, array{pct?: mixed, fraction?: mixed}>  $partials
     * @return array<int, array{at_pct: mixed, sell_pct_of_original: float}>
     */
    private static function partialsToLadder(array $partials): array
    {
        $ladder = [];
        $remaining = 1.0;
        foreach ($partials as $rung) {
            $fraction = (float) ($rung['fraction'] ?? 0);
            $sellPct = $fraction * $remaining * 100;
            if (round($sellPct, 8) > 0) {
                $ladder[] = [
                    'at_pct' => $rung['pct'] ?? null,
                    'sell_pct_of_original' => $sellPct,
                ];
            }
            $remaining *= 1 - $fraction;
        }

        return $ladder;
    }

    /**
     * Maps a v1 definition forward to v2 per the migration table in
     * docs/STRATEGY_SCHEMA_V2.md. Sections with nothing to migrate (empty
     * `setup`/`trigger` rules, no partials/trailing/take_profit rules, no
     * stop rules/time_stop) are left out of the v2 result entirely rather
     * than emitted as empty objects, since every v2 section but `entry` is
     * optional and "absent" is the truer statement than "present but inert".
     *
     * @return array<string, mixed>
     */
    public static function v1ToV2(array $definition): array
    {
        $params = is_array($definition['params'] ?? null) ? $definition['params'] : [];
        $setup = is_array($definition['setup'] ?? null) ? $definition['setup'] : null;
        $trigger = is_array($definition['trigger'] ?? null) ? $definition['trigger'] : null;
        $entry = is_array($definition['entry'] ?? null) ? $definition['entry'] : [];
        $sizing = is_array($entry['sizing'] ?? null) ? $entry['sizing'] : [];
        $management = is_array($definition['management'] ?? null) ? $definition['management'] : [];
        $exit = is_array($definition['exit'] ?? null) ? $definition['exit'] : [];
        $stop = is_array($exit['stop'] ?? null) ? $exit['stop'] : [];
        $exitTakeProfit = is_array($exit['take_profit'] ?? null) ? $exit['take_profit'] : [];
        $timeStop = is_array($exit['time_stop'] ?? null) ? $exit['time_stop'] : null;

        $out = [
            'schema_version' => self::V2_VERSION,
            'key' => $definition['key'] ?? null,
            'author' => $definition['author'] ?? null,
            'meta' => is_array($definition['meta'] ?? null) ? $definition['meta'] : [],
        ];
        foreach (['base', 'suggest'] as $k) {
            if (array_key_exists($k, $definition)) {
                $out[$k] = $definition[$k];
            }
        }
        if ($params !== []) {
            $out['params'] = $params;
        }

        $signals = [];
        $when = [];
        if ($setup !== null) {
            $signals['setup'] = ['all' => $setup['rules'] ?? []];
            $when[] = 'setup';
        }
        if ($trigger !== null) {
            $signals['trigger'] = ['all' => $trigger['rules'] ?? []];
            $when[] = 'trigger';
        }
        if ($when === []) {
            // v1 allowed no setup/trigger at all (a plugin that only narrows risk/sizing and
            // leaves scanning to the base pipeline). An empty `all` group is vacuously true,
            // the same semantics v1 gave a rule-less scan.
            $signals['setup'] = ['all' => []];
            $when[] = 'setup';
        }
        if ($signals !== []) {
            $out['signals'] = $signals;
        }

        $out['entry'] = ['side' => $entry['side'] ?? 'long', 'when' => $when];
        if (isset($trigger['max_candidates'])) {
            $out['entry']['max_candidates'] = $trigger['max_candidates'];
        }
        if (isset($entry['confirm'])) {
            $out['entry']['confirm'] = $entry['confirm'];
        }
        // Every v1 definition sized through the Kelly fallback even when it declared no
        // `entry.sizing` at all, or a sizing block without `kelly_fraction` — write that
        // implicit default down explicitly so v2 validation doesn't require guessing it.
        $out['entry']['size'] = [
            'mode' => 'kelly',
            'fraction' => $sizing['kelly_fraction'] ?? 0.5,
        ];
        if (isset($sizing['max_pct_book'])) {
            $out['entry']['size']['max_pct_book'] = $sizing['max_pct_book'];
        }

        if (! empty($management['adds'])) {
            $out['adds'] = $management['adds'];
        }

        $partials = is_array($management['partials'] ?? null) ? $management['partials'] : [];
        $trailing = is_array($management['trailing'] ?? null) ? $management['trailing'] : null;
        $tpRules = is_array($exitTakeProfit['rules'] ?? null) ? $exitTakeProfit['rules'] : [];
        if ($partials !== [] || $trailing !== null || $tpRules !== []) {
            $takeProfit = ['from' => 'avg', 'reset_on_add' => false];
            if ($partials !== []) {
                $takeProfit['ladder'] = self::partialsToLadder($partials);
            }
            if ($trailing !== null) {
                $takeProfit['runner'] = ['ttp' => [
                    'activate_pct' => $trailing['activate_pct'] ?? null,
                    'giveback_pct' => $trailing['trail_pct'] ?? null,
                ]];
            }
            if ($tpRules !== []) {
                $takeProfit['rules'] = $tpRules;
            }
            $out['take_profit'] = $takeProfit;
        }

        $stopRules = is_array($stop['rules'] ?? null) ? $stop['rules'] : [];
        if ($stopRules !== [] || $timeStop !== null) {
            $out['stop'] = ['anchor' => 'avg'];
            if ($stopRules !== []) {
                $out['stop']['rules'] = $stopRules;
            }
            if ($timeStop !== null) {
                $out['stop']['time_hours'] = $timeStop['hours'] ?? null;
            }
        }

        if (isset($definition['risk']) && is_array($definition['risk'])) {
            $out['risk'] = $definition['risk'];
        }

        return $out;
    }

    /**
     * Best-effort projection of a v2 definition into the v1 canonical
     * shape, for toLegacyView() (the Markdown/Pine exporters). v2
     * mechanics v1 has no field for — sizing modes other than kelly, the
     * ladder, the reentry loop — are left out rather than approximated;
     * the exporters then show whatever v1 can express.
     *
     * @return array<string, mixed>
     */
    public static function v2ToV1View(array $definition): array
    {
        $entry = is_array($definition['entry'] ?? null) ? $definition['entry'] : [];
        $signals = is_array($definition['signals'] ?? null) ? $definition['signals'] : [];
        $size = is_array($entry['size'] ?? null) ? $entry['size'] : [];
        $stop = is_array($definition['stop'] ?? null) ? $definition['stop'] : [];
        $takeProfit = is_array($definition['take_profit'] ?? null) ? $definition['take_profit'] : [];

        // v1's legacy view only ever exported `trigger.rules` as `scan.filters` (see
        // toLegacyView() below) — `setup` and any other named signal has no legacy bucket,
        // so only the signal literally named "trigger" (the v1ToV2() convention) maps back.
        // `any` groups are OR'd in v2 but every v1/exporter consumer of `scan.filters` ANDs
        // the list, so an `any` group is dropped rather than misrepresented as AND — v1 has
        // no field for "one of these", same "best-effort" contract as the ladder/reentry.
        $triggerGroup = is_array($signals['trigger'] ?? null) ? $signals['trigger'] : [];
        $triggerRules = $triggerGroup['all'] ?? [];

        $carried = [];
        foreach (['base', 'suggest'] as $k) {
            if (array_key_exists($k, $definition)) {
                $carried[$k] = $definition[$k];
            }
        }

        return [
            'schema_version' => self::CURRENT_VERSION,
            'key' => $definition['key'] ?? null,
            'author' => $definition['author'] ?? null,
            'meta' => $definition['meta'] ?? [],
            ...$carried,
            'params' => $definition['params'] ?? [],
            'setup' => ['rules' => []],
            'trigger' => [
                'max_candidates' => $entry['max_candidates'] ?? null,
                'rules' => $triggerRules,
            ],
            'entry' => [
                'side' => $entry['side'] ?? 'long',
                'sizing' => ($size['mode'] ?? null) === 'kelly'
                    ? ['kelly_fraction' => $size['fraction'] ?? null, 'max_pct_book' => $size['max_pct_book'] ?? null]
                    : [],
                'confirm' => $entry['confirm'] ?? [],
            ],
            'management' => ['adds' => $definition['adds'] ?? [], 'trailing' => null, 'partials' => []],
            'exit' => [
                'stop' => ['rules' => $stop['rules'] ?? []],
                'take_profit' => ['rules' => $takeProfit['rules'] ?? []],
                'time_stop' => isset($stop['time_hours']) ? ['hours' => $stop['time_hours']] : null,
            ],
            'risk' => $definition['risk'] ?? [],
        ];
    }

    /**
     * Project any definition (legacy or canonical) into the flat legacy shape
     * the exporters read: {key, name, version, base, scan, vet, size, risk}.
     *
     * @return array<string, mixed>
     */
    public static function toLegacyView(array $definition): array
    {
        if (! isset($definition['schema_version'])) {
            return $definition;
        }
        if ($definition['schema_version'] === self::V2_VERSION) {
            return self::toLegacyView(self::v2ToV1View($definition));
        }

        $meta = is_array($definition['meta'] ?? null) ? $definition['meta'] : [];
        $trigger = is_array($definition['trigger'] ?? null) ? $definition['trigger'] : [];
        $entry = is_array($definition['entry'] ?? null) ? $definition['entry'] : [];
        $sizing = is_array($entry['sizing'] ?? null) ? $entry['sizing'] : [];
        $exit = is_array($definition['exit'] ?? null) ? $definition['exit'] : [];
        $stop = is_array($exit['stop'] ?? null) ? $exit['stop'] : [];

        $out = $definition;
        $out['name'] ??= $meta['name'] ?? null;
        $out['description'] ??= $meta['description'] ?? null;
        $out['scan'] = [
            'max_candidates' => $trigger['max_candidates'] ?? null,
            'filters' => $trigger['rules'] ?? [],
        ];
        $out['vet'] = ['rules' => $entry['confirm'] ?? []];
        $out['size'] = [
            'kelly_fraction' => $sizing['kelly_fraction'] ?? null,
            'max_pct_book' => $sizing['max_pct_book'] ?? null,
        ];
        $out['risk'] = ['rules' => $stop['rules'] ?? []];

        return $out;
    }
}
