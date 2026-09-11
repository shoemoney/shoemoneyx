<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\DeskOptimize;
use App\Desk\Backtester;
use App\Desk\DeskContext;
use App\Desk\Settings;
use App\Desk\StrategyRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: optimizer candidates used to be written only at the global layer, so a coin's own
 * per_product.<pid>.* champion settings shadowed them via forProduct() and every candidate in a
 * round scored identically. The candidate must win over the champion for the coin under test.
 */
class OptimizerCandidateParamsTest extends TestCase
{
    use RefreshDatabase;

    public function test_candidate_beats_the_coins_champion_settings(): void
    {
        config(['cache.default' => 'array', 'desk.strategy' => 'mr']);
        $this->artisan('desk:coin', ['action' => 'set', 'product' => 'btc-usd', 'kv' => ['mr.timeframe=2m', 'mr.engine.x1=4', 'mr.engine.base_minutes=720', 'mr.tp_max_pct=5', 'mr.tp_rungs=10']])->assertSuccessful();
        $this->artisan('desk:coin', ['action' => 'set', 'product' => 'eth-usd', 'kv' => ['mr.timeframe=3m']])->assertSuccessful();

        $candidate = ['mr.timeframe' => '1m', 'mr.engine.x1' => 9, 'mr.engine.base_minutes' => 240, 'mr.tp_max_pct' => 3, 'mr.tp_rungs' => 5];
        $overrides = DeskOptimize::candidateParams('BTC-USD', $candidate, 'train', 7);

        // The exact merge Backtester::simulate() performs before handing params to the strategy.
        $settings = app(Settings::class);
        $strategy = app(StrategyRegistry::class)->make('mr');
        $ctx = new DeskContext(array_replace_recursive($settings->merged($strategy), Backtester::undot($overrides)), 'paper', backtest: true);

        $btc = $ctx->forProduct('BTC-USD');
        foreach ($candidate as $key => $value) {
            $this->assertSame($value, $btc->param($key), "{$key} was shadowed by the BTC champion");
        }
        $this->assertSame(['coin' => 'BTC-USD', 'window' => 'train', 'cand' => 7, 'side' => 'long', 'batch' => null, 'tag' => null, 'fold' => null], $ctx->param('_opt'));

        // Other coins keep their own champion: the candidate is scoped to the coin under test.
        $this->assertSame('3m', $ctx->forProduct('ETH-USD')->param('mr.timeframe'));
    }

    public function test_candidate_values_win_over_the_optimizers_fixed_defaults(): void
    {
        $p = DeskOptimize::candidateParams('BTC-USD', ['mr.qty_pct' => 40, 'mr.allow_shorts' => true, 'mr.engine.jac_len' => 8], 'train', 0);

        $this->assertSame(40, $p['mr.qty_pct']);
        $this->assertSame(40, $p['per_product.BTC-USD.mr.qty_pct']);
        $this->assertTrue($p['mr.allow_shorts']);
        $this->assertSame(8, $p['per_product.BTC-USD.mr.engine.jac_len']);
        $this->assertSame(0.0003, $p['fees.taker_rate']);   // untouched fixed keys still apply
    }

    public function test_a_strategy_writes_into_its_own_short_namespace(): void
    {
        $p = DeskOptimize::candidateParams('ETH-USD', ['mr.timeframe' => '3m'], 'train', 4, 'short', strategy: 'mr');

        $this->assertSame('3m', $p['mr.short.timeframe']);
        $this->assertArrayNotHasKey('mr.timeframe', $p, 'the long set is never touched by a shorts sweep');
        $this->assertFalse($p['mr.allow_longs']);
        $this->assertTrue($p['mr.allow_shorts']);
        $this->assertSame(0.0003, $p['fees.taker_rate'], 'namespace-agnostic FIXED entries still apply');
    }

    /**
     * Mid-task addition: --side=both sweeps the mr.short.* set exactly like --side=short, but leaves
     * longs on and carries the coin's CURRENT long champion alongside the swept short keys, so the
     * candidate backtest scores combined long+short PnL. Bookkeeping (_opt.side, per_product mirroring)
     * still reports 'short' so the arena/optimizer side filter (which only knows long|short) keeps working.
     */
    public function test_side_both_sweeps_short_but_carries_the_long_champion_with_longs_enabled(): void
    {
        $longChampion = ['mr.timeframe' => '5m', 'mr.qty_pct' => 20];
        $p = DeskOptimize::candidateParams('BTC-USD', ['mr.timeframe' => '2m'], 'train', 3, 'both', longChampion: $longChampion);

        $this->assertSame('2m', $p['mr.short.timeframe'], 'the swept key lands in the short namespace');
        $this->assertSame('5m', $p['mr.timeframe'], 'the long champion timeframe rides along unmutated');
        $this->assertSame(20, $p['mr.qty_pct'], 'the long champion qty_pct rides along unmutated');
        $this->assertTrue($p['mr.allow_longs'], 'longs stay enabled for a mixed round');
        $this->assertTrue($p['mr.allow_shorts']);
        $this->assertSame('short', $p['_opt']['side'], 'bookkeeping side stays "short" so the arena/optimizer side filter still works');
        $this->assertSame(20, $p['per_product.BTC-USD.mr.qty_pct'], 'per_product mirror carries the long champion key too');
        $this->assertSame('2m', $p['per_product.BTC-USD.mr.short.timeframe']);
    }
}
