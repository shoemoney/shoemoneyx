<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Backtester;
use Tests\TestCase;

/** Backtester::timeframesUsedBy() walks a definition's rule arrays for "tf" overrides + meta.timeframe (phase A preload). */
class BacktesterTimeframesUsedByTest extends TestCase
{
    public function test_collects_meta_timeframe_and_every_rule_tf_override(): void
    {
        $def = [
            'meta' => ['timeframe' => '1h'],
            'setup' => ['rules' => [
                ['field' => 'ind.rsi(14)', 'op' => '<', 'value' => 30, 'tf' => '15m'],
            ]],
            'trigger' => ['rules' => [
                ['field' => 'spread_bps', 'op' => '<', 'value' => 20],   // no tf override
            ]],
            'entry' => ['confirm' => [
                ['field' => 'ind.ema(50)', 'op' => '>', 'value' => 100, 'tf' => '6H'],
            ]],
            'management' => ['adds' => [
                ['trigger' => ['field' => 'ind.atr(14)', 'op' => '>', 'value' => 1, 'tf' => '15m']],
            ]],
        ];

        $tfs = Backtester::timeframesUsedBy($def);
        sort($tfs);

        $this->assertSame(['15m', '1h', '6H'], $tfs);
    }

    public function test_no_rules_yields_just_meta_timeframe(): void
    {
        $this->assertSame(['1h'], Backtester::timeframesUsedBy(['meta' => ['timeframe' => '1h']]));
    }

    public function test_no_meta_timeframe_and_no_overrides_yields_nothing(): void
    {
        $this->assertSame([], Backtester::timeframesUsedBy(['meta' => [], 'setup' => ['rules' => [
            ['field' => 'spread_bps', 'op' => '<', 'value' => 20],
        ]]]));
    }
}
