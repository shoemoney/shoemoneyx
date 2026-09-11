<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Events\BacktestScored;
use App\Events\ChampionPromoted;
use App\Events\OptimizerRoundScored;
use App\Models\Backtest;
use App\Models\OptimizerRound;
use Tests\TestCase;

/** The firehose payloads: what the dashboard toasts and charts rely on. */
class OptimizerEventsTest extends TestCase
{
    public function test_backtest_scored_reads_the_side_namespace_and_compacts_stats(): void
    {
        $b = new Backtest([
            'products' => ['ETH-USD'], 'status' => 'done', 'starting_cash' => 10000, 'ending_equity' => 10250,
            'params' => ['mr.short.timeframe' => '4m', 'mr.short.short_stop_pct' => 3, 'mr.short.tsl_pct' => 1, 'mr.timeframe' => '2m', 'mr.leverage' => 2, 'mr.ttp_activate_pct' => 2, 'mr.ttp_giveback_pct' => 0.3, '_opt' => ['coin' => 'ETH-USD', 'window' => 'test', 'cand' => 12, 'side' => 'short']],
            'stats' => ['total_return_pct' => 2.5, 'trades' => 31, 'win_rate' => 74.2, 'profit_factor' => 2.1, 'max_drawdown_pct' => 1.3],
        ]);
        $e = new BacktestScored($b);

        $this->assertSame('optimizer', $e->broadcastOn()[0]->name);
        $this->assertSame('backtest.scored', $e->broadcastAs());
        $w = $e->broadcastWith();
        $this->assertSame('ETH-USD', $w['coin']);
        $this->assertSame('short', $w['side']);
        $this->assertSame('4m', $w['params']['tf'], 'short runs read the mr.short.* set');
        $this->assertSame(3, $w['params']['stop']);
        $this->assertSame(2, $w['params']['lev'], 'falls back to mr.* when the short key is absent');
        $this->assertSame(1, $w['params']['tsl'], 'short runs pick up mr.short.tsl_pct');
        $this->assertSame(2, $w['params']['ttp'], 'ttp falls back to mr.* when the short key is absent');
        $this->assertSame(0.3, $w['params']['ttpg']);
        $this->assertSame(2.5, $w['ret']);
        $this->assertSame(31, $w['trades']);
        $this->assertArrayNotHasKey('equity_curve', $w, 'never ships the heavy columns');
    }

    public function test_round_scored_carries_verdict_and_both_scores(): void
    {
        $r = new OptimizerRound(['product_id' => 'SOL-USD', 'side' => 'long', 'candidates' => 97, 'champion_train' => 9.0, 'champion_test' => 1.18, 'best_train' => 9.5, 'best_test' => 2.0, 'best_calmar' => 3.4, 'best_dsr' => 0.97, 'best_plateau' => 0.82, 'rank' => 'calmar', 'cash' => 3000, 'tag' => 'squeeze-v2', 'cache_hits' => 6, 'promoted' => true, 'note' => 'promoted', 'champion_params' => [], 'best_params' => ['mr.timeframe' => '1m']]);
        $w = (new OptimizerRoundScored($r))->broadcastWith();

        $this->assertSame('round.scored', (new OptimizerRoundScored($r))->broadcastAs());
        $this->assertSame('SOL-USD', $w['coin']);
        $this->assertTrue($w['promoted']);
        $this->assertSame(9.5, $w['best']['train']);
        $this->assertSame('1m', $w['best']['params']['mr.timeframe']);
        $this->assertSame(3.4, $w['best']['calmar']);
        $this->assertSame(0.97, $w['best']['dsr']);
        $this->assertSame(0.82, $w['best']['plateau']);
        $this->assertSame('calmar', $w['rank']);
        $this->assertSame(3000.0, $w['cash']);
        $this->assertSame('squeeze-v2', $w['tag']);
        $this->assertSame(6, $w['cache_hits']);
    }

    public function test_champion_promoted_diff_lists_only_the_keys_that_changed(): void
    {
        $e = new ChampionPromoted('BTC-USD', 'long',
            ['mr.timeframe' => '2m', 'mr.engine.x1' => 4, 'mr.leverage' => 1, 'mr.tp_max_pct' => 5],
            ['mr.timeframe' => '2m', 'mr.engine.x1' => 4, 'mr.leverage' => 3, 'mr.tp_max_pct' => 8],
            5.75, 2.06, 3.62, 2.23);
        $w = $e->broadcastWith();

        $this->assertSame('champion.promoted', $e->broadcastAs());
        $this->assertSame(['mr.leverage' => [1, 3], 'mr.tp_max_pct' => [5, 8]], $w['diff']);
        $this->assertSame(5.75, $w['train']);
        $this->assertSame(2.23, $w['champion']['test']);
    }
}
