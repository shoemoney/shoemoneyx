<?php

declare(strict_types=1);

namespace App\Desk\Strategies;

/**
 * Renders a strategy-plugin JSON as Pine Script (v6 strategy) for TradingView.
 * Only chart-native fields map to Pine; tape/book fields (buys, spread, depth)
 * cannot exist on a chart and are emitted as WARNING comments instead of
 * silently dropped logic. Paste the output on a 1H chart — the H1-relative
 * fields assume hourly bars.
 */
final class PluginPineExporter
{
    public static function export(array $def): string
    {
        $name = preg_replace('/[^A-Za-z0-9 _-]/', '', (string) ($def['name'] ?? $def['key'] ?? 'plugin'));
        $warnings = [];
        $inputs = [];
        $entryConds = [];
        $exitConds = [];

        $expr = function (array $rule, string $prefix) use (&$inputs, &$warnings): ?string {
            [$pine, $warn] = self::mapField((string) ($rule['field'] ?? ''));
            if ($pine === null) {
                $warnings[] = $warn;

                return null;
            }
            $id = $prefix.'_'.preg_replace('/[^a-z0-9]+/', '_', strtolower((string) ($rule['field'] ?? 'f')));
            $inputs[] = sprintf('%s = input.float(%s, "%s %s")', $id, self::num($rule['value'] ?? 0), $rule['field'] ?? '', $rule['op'] ?? '');
            $op = self::mapOp((string) ($rule['op'] ?? ''));
            if ($op === null) {
                $warnings[] = 'unsupported op '.($rule['op'] ?? '?').' on '.($rule['field'] ?? '?').' — skipped';

                return null;
            }

            return sprintf('%s %s %s', $pine, $op, $id);
        };

        foreach ($def['scan']['filters'] ?? [] as $i => $rule) {
            $c = $expr($rule, "scan{$i}");
            if ($c !== null) {
                $entryConds[] = $c;
            }
        }
        foreach ($def['vet']['rules'] ?? [] as $i => $rule) {
            $c = $expr($rule, "vet{$i}");
            if ($c !== null) {
                $entryConds[] = $c;
            }
        }
        foreach ($def['risk']['rules'] ?? [] as $i => $rule) {
            $c = $expr($rule, "risk{$i}");
            if ($c !== null) {
                $exitConds[] = $c;
            }
        }

        $lines = [];
        $lines[] = '//@version=6';
        $lines[] = sprintf('strategy("%s", overlay=true, default_qty_type=strategy.percent_of_equity, default_qty_value=10)', $name);
        $lines[] = '// Exported from the shoemoneyx strategy builder. Paste on a 1H chart.';
        $lines[] = '// Sizing on the desk uses Kelly; here a flat 10% of equity stands in.';
        foreach ($warnings as $w) {
            $lines[] = '// WARNING: '.$w;
        }
        $lines[] = '';
        $lines[] = '[macdLine, signalLine, histLine] = ta.macd(close, 12, 26, 9)';
        foreach ($inputs as $in) {
            $lines[] = $in;
        }
        $lines[] = '';
        $lines[] = 'enterLong = '.($entryConds === [] ? 'false // no mappable entry rules' : implode(' and ', $entryConds));
        $lines[] = 'exitLong = '.($exitConds === [] ? 'false // no mappable exit rules' : implode(' or ', $exitConds));
        $lines[] = '';
        $lines[] = 'if enterLong';
        $lines[] = '    strategy.entry("Long", strategy.long)';
        $lines[] = 'if exitLong';
        $lines[] = '    strategy.close("Long")';
        $lines[] = '';
        $lines[] = 'plotshape(enterLong, title="Entry", style=shape.triangleup, location=location.belowbar, color=color.green, size=size.small)';
        $lines[] = 'plotshape(exitLong, title="Exit", style=shape.triangledown, location=location.abovebar, color=color.red, size=size.small)';

        return implode("\n", $lines)."\n";
    }

    /** @return array{0: ?string, 1: string} [pine expr, warning when unmappable] */
    private static function mapField(string $field): array
    {
        return match ($field) {
            'price' => ['close', ''],
            'indicators.rsi14', 'extra.indicators.rsi14' => ['ta.rsi(close, 14)', ''],
            'indicators.ema9', 'extra.indicators.ema9' => ['ta.ema(close, 9)', ''],
            'indicators.ema21', 'extra.indicators.ema21' => ['ta.ema(close, 21)', ''],
            'indicators.ema50', 'extra.indicators.ema50' => ['ta.ema(close, 50)', ''],
            'indicators.macd', 'extra.indicators.macd' => ['macdLine', ''],
            'indicators.signal', 'extra.indicators.signal' => ['signalLine', ''],
            'indicators.hist', 'extra.indicators.hist' => ['histLine', ''],
            'indicators.atr14', 'extra.indicators.atr14' => ['ta.atr(14)', ''],
            'price_change_h1_pct' => ['(close - close[1]) / close[1] * 100', ''],
            'price_change_h6_pct' => ['(close - close[6]) / close[6] * 100', ''],
            'price_change_h24_pct' => ['(close - close[24]) / close[24] * 100', ''],
            'volume_surge_h1' => ['volume / ta.sma(volume, 24)', ''],
            'volume_ratio_6h' => ['math.sum(volume, 6) / (math.sum(volume, 24) / 4)', ''],
            'position.pnl_pct' => ['(close - strategy.opentrades.entry_price(0)) / strategy.opentrades.entry_price(0) * 100', ''],
            default => [null, $field.' has no TradingView equivalent (tape/book/position data) — skipped'],
        };
    }

    private static function mapOp(string $op): ?string
    {
        return match ($op) {
            '<', '<=', '>', '>=', '==', '!=' => $op,
            default => null,
        };
    }

    private static function num(mixed $v): string
    {
        return is_numeric($v) ? (string) $v : '0';
    }
}
