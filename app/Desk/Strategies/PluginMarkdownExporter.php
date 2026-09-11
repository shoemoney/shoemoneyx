<?php

declare(strict_types=1);

namespace App\Desk\Strategies;

/**
 * Renders a strategy-plugin JSON as a human-readable Markdown brief:
 * the edge, the exact rules, the tunables, and how to test it.
 */
final class PluginMarkdownExporter
{
    public static function export(array $def, ?array $backtest = null): string
    {
        $l = [];
        $l[] = '# '.($def['name'] ?? $def['key'] ?? 'strategy');
        $l[] = '';
        if (! empty($def['description'])) {
            $l[] = '> '.$def['description'];
            $l[] = '';
        }
        $l[] = sprintf('plugin `%s` · v%s · base `%s` · exported %s', $def['key'] ?? '?', $def['version'] ?? '?', $def['base'] ?? 'custom', gmdate('Y-m-d'));
        $l[] = '';
        $l[] = '## Scan (all filters must pass)';
        $l[] = '';
        self::rules($def['scan']['filters'] ?? [], $l);
        $l[] = '## Vet (first failure rejects)';
        $l[] = '';
        self::rules($def['vet']['rules'] ?? [], $l, 'reason');
        $l[] = '## Size';
        $l[] = '';
        foreach ($def['size'] ?? [] as $k => $v) {
            $l[] = sprintf('- `%s` = `%s`', $k, is_scalar($v) ? $v : json_encode($v));
        }
        if (empty($def['size'] ?? [])) {
            $l[] = '_desk defaults_';
        }
        $l[] = '';
        $l[] = '## Risk (first match closes)';
        $l[] = '';
        self::rules($def['risk']['rules'] ?? [], $l, 'action');
        if (! empty($def['params'])) {
            $l[] = '## Tunables';
            $l[] = '';
            foreach ($def['params'] as $k => $v) {
                $l[] = sprintf('- `%s` = `%s`', $k, is_scalar($v) ? $v : json_encode($v));
            }
            $l[] = '';
        }
        if ($backtest !== null) {
            $l[] = '## Last backtest';
            $l[] = '';
            $l[] = sprintf('- return `%s%%`, trades `%s`, wins `%s` / losses `%s`', $backtest['return_pct'] ?? '?', $backtest['trades'] ?? '?', $backtest['wins'] ?? '?', $backtest['losses'] ?? '?');
            $l[] = '';
        }
        $l[] = '_Paper first. Backtests do not establish future performance._';

        return implode("\n", $l)."\n";
    }

    /** @param array<int, string> $l */
    private static function rules(mixed $rules, array &$l, ?string $extra = null): void
    {
        if (! is_array($rules) || $rules === []) {
            $l[] = '_none_';
            $l[] = '';

            return;
        }
        foreach ($rules as $rule) {
            $line = sprintf('- `%s %s %s`', $rule['field'] ?? '?', $rule['op'] ?? '?', is_scalar($rule['value'] ?? null) ? $rule['value'] : json_encode($rule['value'] ?? null));
            if ($extra !== null && isset($rule[$extra])) {
                $line .= sprintf(' → %s', $rule[$extra]);
            }
            $l[] = $line;
        }
        $l[] = '';
    }
}
