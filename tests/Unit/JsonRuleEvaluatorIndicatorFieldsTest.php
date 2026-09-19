<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Data\ProductStats;
use App\Desk\DeskContext;
use App\Desk\Strategies\IndicatorField;
use App\Desk\Strategies\JsonRuleEvaluator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `ind.*` fields, field-to-field values, crosses_above/below, per-rule "tf", and the v1
 * `indicators.*` aliases, all through JsonRuleEvaluator (docs/STRATEGY_SCHEMA_V2.md, phase A).
 */
class JsonRuleEvaluatorIndicatorFieldsTest extends TestCase
{
    private function stats(array $extra = []): ProductStats
    {
        return new ProductStats(
            productId: 'BTC-USD',
            price: 100.0,
            bestBid: 99.9,
            bestAsk: 100.1,
            ageHours: 100.0,
            volumeM5Usd: 1000.0,
            volumeH1Usd: 12000.0,
            volumeH6Usd: 60000.0,
            volumeH24Usd: 240000.0,
            volumePrevH24Usd: 200000.0,
            priceChangeM5Pct: 0.1,
            priceChangeH1Pct: 0.5,
            priceChangeH6Pct: 1.0,
            priceChangeH24Pct: 2.0,
            buysH1: 60,
            sellsH1: 40,
            buysM5: 6,
            sellsM5: 4,
            buyVolumeH1Usd: 7000.0,
            sellVolumeH1Usd: 5000.0,
            spreadBps: 10.0,
            bookDepthUsd: 50000.0,
            candlesH1Count: 100,
            extra: $extra,
        );
    }

    /** @param array<int, float> $closes oldest -> newest, one per hour ending at $nowTs */
    private function hourlyBars(array $closes, int $nowTs): array
    {
        $n = count($closes);
        $bars = [];
        foreach ($closes as $i => $c) {
            $start = $nowTs - ($n - $i) * 3600;
            $bars[] = ['start' => $start, 'open' => $c, 'high' => $c, 'low' => $c, 'close' => $c, 'volume' => 10.0];
        }

        return $bars;
    }

    /**
     * A DeskContext whose bars() is served entirely from an in-memory map, keyed by timeframe
     * (case-insensitively — IndicatorCache normalises "1h" to Candle::DURATIONS's own "1H").
     */
    private function ctxWithBars(array $barsByTf, int $nowTs): DeskContext
    {
        $provider = function (string $pid, string $tf, int $from, int $to) use ($barsByTf): array {
            foreach ($barsByTf as $k => $bars) {
                if (strcasecmp($k, $tf) === 0) {
                    return $bars;
                }
            }

            return [];
        };

        return new DeskContext([], 'backtest', false, [], new \DateTimeImmutable('@'.$nowTs), true, $provider);
    }

    #[Test]
    public function ind_field_resolves_the_current_value(): void
    {
        // closes 1..6 hourly; sma(3) over the last 3 closed bars (4,5,6) = 5.
        $now = strtotime('2026-01-01T06:00:00Z');
        $ctx = $this->ctxWithBars(['1h' => $this->hourlyBars([1, 2, 3, 4, 5, 6], $now)], $now);

        $this->assertEqualsWithDelta(5.0, JsonRuleEvaluator::value('ind.sma(3)', $this->stats(), null, $ctx, '1h'), 1e-9);
    }

    #[Test]
    public function unknown_ind_field_never_fires(): void
    {
        $now = strtotime('2026-01-01T06:00:00Z');
        $ctx = $this->ctxWithBars(['1h' => $this->hourlyBars([1, 2, 3], $now)], $now);

        $this->assertNull(JsonRuleEvaluator::value('ind.bogus(1)', $this->stats(), null, $ctx, '1h'));
        $this->assertFalse(JsonRuleEvaluator::fires(['field' => 'ind.bogus(1)', 'op' => '>', 'value' => 0], $this->stats(), null, $ctx));
    }

    #[Test]
    public function literal_comparison_against_an_indicator_field(): void
    {
        $now = strtotime('2026-01-01T06:00:00Z');
        $ctx = $this->ctxWithBars(['1h' => $this->hourlyBars([1, 2, 3, 4, 5, 6], $now)], $now);

        $this->assertTrue(JsonRuleEvaluator::fires(['field' => 'ind.sma(3)', 'op' => '>', 'value' => 4], $this->stats(), null, $ctx));
        $this->assertFalse(JsonRuleEvaluator::fires(['field' => 'ind.sma(3)', 'op' => '>', 'value' => 6], $this->stats(), null, $ctx));
    }

    #[Test]
    public function field_to_field_value_compares_two_indicators(): void
    {
        $now = strtotime('2026-01-01T06:00:00Z');
        $ctx = $this->ctxWithBars(['1h' => $this->hourlyBars([1, 1, 1, 1, 1, 1], $now)], $now);

        // sma(2) == sma(4) on a flat series.
        $this->assertTrue(JsonRuleEvaluator::fires(
            ['field' => 'ind.sma(2)', 'op' => '==', 'value' => ['field' => 'ind.sma(4)']],
            $this->stats(),
            null,
            $ctx,
        ));
    }

    #[Test]
    public function crosses_above_fires_only_on_the_bar_it_crosses(): void
    {
        // closes 1,1,1,1,1,10: sma(2) jumps from 1 to 5.5 on the last bar, sma(4) only to 3.25 --
        // the short average crosses above the long one exactly at this bar.
        $now = strtotime('2026-01-01T06:00:00Z');
        $ctx = $this->ctxWithBars(['1h' => $this->hourlyBars([1, 1, 1, 1, 1, 10], $now)], $now);

        $rule = ['field' => 'ind.sma(2)', 'op' => 'crosses_above', 'value' => ['field' => 'ind.sma(4)']];
        $this->assertTrue(JsonRuleEvaluator::fires($rule, $this->stats(), null, $ctx));
    }

    #[Test]
    public function crosses_below_mirrors_crosses_above(): void
    {
        // Mirror image: sma(2) drops from 10 to 5.0 while sma(4) only drops to 7.5.
        $now = strtotime('2026-01-01T06:00:00Z');
        $ctx = $this->ctxWithBars(['1h' => $this->hourlyBars([10, 10, 10, 10, 10, 0], $now)], $now);

        $rule = ['field' => 'ind.sma(2)', 'op' => 'crosses_below', 'value' => ['field' => 'ind.sma(4)']];
        $this->assertTrue(JsonRuleEvaluator::fires($rule, $this->stats(), null, $ctx));
    }

    #[Test]
    public function crosses_never_fires_with_no_previous_bar(): void
    {
        // A single bar has no "previous" — the rule must never fire, not error.
        $now = strtotime('2026-01-01T06:00:00Z');
        $ctx = $this->ctxWithBars(['1h' => $this->hourlyBars([5], $now)], $now);

        $rule = ['field' => 'ind.sma(1)', 'op' => 'crosses_above', 'value' => 1];
        $this->assertFalse(JsonRuleEvaluator::fires($rule, $this->stats(), null, $ctx));
    }

    #[Test]
    public function crosses_that_never_actually_crosses_does_not_fire(): void
    {
        // sma(2) stays above sma(4) on both the previous and current bar -- no crossing event.
        $now = strtotime('2026-01-01T06:00:00Z');
        $ctx = $this->ctxWithBars(['1h' => $this->hourlyBars([10, 10, 10, 10, 10, 10], $now)], $now);

        $rule = ['field' => 'ind.sma(2)', 'op' => 'crosses_above', 'value' => ['field' => 'ind.sma(4)']];
        $this->assertFalse(JsonRuleEvaluator::fires($rule, $this->stats(), null, $ctx));
    }

    #[Test]
    public function per_rule_tf_overrides_the_default(): void
    {
        $now = strtotime('2026-01-01T06:00:00Z');
        $ctx = $this->ctxWithBars([
            '1h' => $this->hourlyBars([1, 1, 1], $now),
            '15m' => $this->hourlyBars([100, 100, 100], $now),
        ], $now);

        // Same field, different tf: the default (1h) sees ~1, the override (15m) sees ~100.
        $this->assertEqualsWithDelta(1.0, JsonRuleEvaluator::value('ind.sma(2)', $this->stats(), null, $ctx, '1h'), 1e-9);
        $this->assertTrue(JsonRuleEvaluator::fires(['field' => 'ind.sma(2)', 'tf' => '15m', 'op' => '>', 'value' => 50], $this->stats(), null, $ctx, '1h'));
        $this->assertFalse(JsonRuleEvaluator::fires(['field' => 'ind.sma(2)', 'op' => '>', 'value' => 50], $this->stats(), null, $ctx, '1h'));
    }

    #[Test]
    public function v1_indicators_alias_still_resolves_unchanged(): void
    {
        $s = $this->stats(['indicators' => ['rsi14' => 42.5]]);
        $ctx = $this->ctxWithBars([], strtotime('2026-01-01T00:00:00Z'));

        $this->assertSame(42.5, JsonRuleEvaluator::value('indicators.rsi14', $s, null, $ctx));
        $this->assertTrue(JsonRuleEvaluator::fires(['field' => 'indicators.rsi14', 'op' => '<', 'value' => 50], $s, null, $ctx));
    }

    #[Test]
    public function ind_smx_div_bull_and_div_bear_resolve_instead_of_null(): void
    {
        $now = strtotime('2026-01-01T00:00:00Z');
        $ctx = $this->ctxWithBars(['1h' => $this->syntheticBars(400, $now)], $now);
        $s = $this->stats();

        $this->assertNotNull(JsonRuleEvaluator::value('ind.smx.div_bull', $s, null, $ctx, '1h'));
        $this->assertNotNull(JsonRuleEvaluator::value('ind.smx.div_bear', $s, null, $ctx, '1h'));
        $this->assertIsBool(JsonRuleEvaluator::value('ind.smx.div_bull', $s, null, $ctx, '1h'));
    }

    #[Test]
    public function every_declared_indicator_output_resolves_non_null_on_a_long_series(): void
    {
        $now = strtotime('2026-01-01T00:00:00Z');
        $ctx = $this->ctxWithBars(['1h' => $this->syntheticBars(400, $now)], $now);
        $s = $this->stats();

        foreach (IndicatorField::table() as $name => $spec) {
            $args = match ($name) {
                'macd' => [12, 26, 9],
                'bb' => [20, 2],
                default => $spec['arity'] > 0 ? [14] : [],
            };
            $field = $spec['arity'] > 0 ? "ind.{$name}(".implode(',', $args).')' : "ind.{$name}";

            foreach ($spec['outputs'] as $output) {
                $this->assertNotNull(
                    JsonRuleEvaluator::value("{$field}.{$output}", $s, null, $ctx, '1h'),
                    "{$field}.{$output} resolved null"
                );
            }
        }
    }

    #[Test]
    public function an_indicator_never_sees_the_bar_it_is_standing_inside(): void
    {
        $now = strtotime('2026-01-01T06:00:00Z');
        $bars = $this->hourlyBars([100, 101], $now);
        // A bar that starts exactly "now" is still open -- it must never leak into the series.
        $bars[] = ['start' => $now, 'open' => 9999.0, 'high' => 9999.0, 'low' => 9999.0, 'close' => 9999.0, 'volume' => 10.0];
        $ctx = $this->ctxWithBars(['1h' => $bars], $now);

        $this->assertEqualsWithDelta(101.0, JsonRuleEvaluator::value('ind.sma(1)', $this->stats(), null, $ctx, '1h'), 1e-9);
    }

    #[Test]
    public function crosses_above_does_not_refire_on_the_bar_after_the_cross(): void
    {
        // Bar 6 is the crossing bar (see crosses_above_fires_only_on_the_bar_it_crosses); bar 7
        // keeps trending the same direction, so sma(2) is already above sma(4) on both bars
        // compared -- no new crossing event, and the rule must not fire again.
        $now = strtotime('2026-01-01T07:00:00Z');
        $ctx = $this->ctxWithBars(['1h' => $this->hourlyBars([1, 1, 1, 1, 1, 10, 11], $now)], $now);

        $rule = ['field' => 'ind.sma(2)', 'op' => 'crosses_above', 'value' => ['field' => 'ind.sma(4)']];
        $this->assertFalse(JsonRuleEvaluator::fires($rule, $this->stats(), null, $ctx));
    }

    /** @return array<int, array{start:int,open:float,high:float,low:float,close:float,volume:float}> */
    private function syntheticBars(int $n, int $nowTs): array
    {
        $bars = [];
        for ($i = 0; $i < $n; $i++) {
            $start = $nowTs - ($n - $i) * 3600;
            $close = 100 + 10 * sin($i / 9) + $i * 0.02;
            $open = 100 + 10 * sin(($i - 1) / 9) + ($i - 1) * 0.02;
            $bars[] = [
                'start' => $start,
                'open' => $open,
                'high' => max($open, $close) + 0.5,
                'low' => min($open, $close) - 0.5,
                'close' => $close,
                'volume' => 100.0 + $i,
            ];
        }

        return $bars;
    }
}
