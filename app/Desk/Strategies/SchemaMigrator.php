<?php

declare(strict_types=1);

namespace App\Desk\Strategies;

/**
 * Bridges the two strategy-plugin definition shapes.
 *
 * - Legacy (no `schema_version` key): the flat `scan` / `vet` / `size` / `risk`
 *   shape documented in docs/STRATEGY_PLUGIN.md — every plugin saved before
 *   the formal schema landed.
 * - Canonical v1 (`schema_version: 1`): the sectioned `meta` / `params` /
 *   `setup` / `trigger` / `entry` / `management` / `exit` / `risk` shape
 *   documented in docs/STRATEGY_SCHEMA.md.
 *
 * `migrate()` is what JsonPluginStrategy executes against: it always returns
 * the canonical shape, mapping legacy fields forward so plugins saved before
 * the schema existed keep running unchanged. `toLegacyView()` is the inverse,
 * used by the Markdown/Pine exporters so they keep reading the flat shape
 * regardless of which shape the plugin was authored in.
 */
final class SchemaMigrator
{
    public const CURRENT_VERSION = 1;

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
