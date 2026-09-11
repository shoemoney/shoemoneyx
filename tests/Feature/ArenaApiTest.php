<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Backtest;
use App\Models\OptimizerRound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ArenaApiTest extends TestCase
{
    use RefreshDatabase;

    private function backtest(string $coin, string $side, string $win, int $cand, string $batch, float $ret, ?string $createdAt = null): void
    {
        Backtest::create([
            'strategy' => 'mr', 'products' => [$coin], 'from' => now()->subDays(8), 'to' => now()->subDay(), 'starting_cash' => 10000,
            'status' => 'done',
            'params' => ['_opt' => ['coin' => $coin, 'window' => $win, 'cand' => $cand, 'side' => $side, 'batch' => $batch]]
                + ($side === 'short' ? ['mr.short.timeframe' => '4m', 'mr.leverage' => 2] : ['mr.timeframe' => '2m', 'mr.leverage' => 3]),
            'stats' => ['total_return_pct' => $ret, 'trades' => 40, 'profit_factor' => 1.8, 'max_drawdown_pct' => 2.2],
            'created_at' => $createdAt ?? now(),
        ]);
    }

    public function test_returns_champion_set_recent_candidates_and_todays_tally(): void
    {
        // Fixed at noon UTC, far from midnight in either direction: the fixture below plants a
        // promotion at "now()->subHour()" and asserts it counts as today, which flaked whenever
        // the suite happened to run in the first hour after UTC midnight.
        $this->travelTo(Carbon::parse('2026-09-05 12:00:00', 'UTC'));

        config(['desk.strategy' => 'mr', 'desk.perps.active' => ['BTC-USD']]);
        $this->artisan('desk:coin', ['action' => 'set', 'product' => 'btc-usd', 'kv' => ['mr.timeframe=2m', 'mr.leverage=3']])->assertSuccessful();

        $base = ['strategy' => 'mr', 'product_id' => 'BTC-USD', 'side' => 'long', 'train_from' => now()->subDays(30), 'train_to' => now()->subDays(7), 'test_from' => now()->subDays(7), 'test_to' => now(), 'candidates' => 97, 'champion_params' => []];
        OptimizerRound::create($base + ['champion_train' => 5.38, 'champion_test' => 2.34, 'promoted' => false, 'note' => 'kept', 'created_at' => now()->subHours(2)]);
        OptimizerRound::create($base + ['best_train' => 6.1, 'best_test' => 3.02, 'best_params' => ['mr.timeframe' => '2m'], 'promoted' => true, 'note' => 'promoted', 'created_at' => now()->subHour()]);

        $this->backtest('BTC-USD', 'long', 'train', 0, 'b1', 5.4);
        $this->backtest('BTC-USD', 'long', 'test', 0, 'b1', 2.1);
        $this->backtest('BTC-USD', 'long', 'train', 1, 'b2', 1.0);          // unpaired: no test row yet

        $r = $this->getJson('/api/arena/optimizer?coin=BTC-USD&side=long')->assertOk()->json();

        $this->assertSame('BTC-USD', $r['coin']);
        $this->assertSame('long', $r['side']);
        $this->assertSame('2m', $r['champion']['set']['timeframe']);
        $this->assertSame(3, $r['champion']['set']['leverage']);
        $this->assertEquals(6.1, $r['champion']['train'], 'the latest round was promoted, so it reports the best/new score');
        $this->assertEquals(3.02, $r['champion']['test']);
        $this->assertNotNull($r['champion']['promoted_at']);

        $this->assertCount(1, $r['recent'], 'only the paired candidate');
        $this->assertEquals(5.4, $r['recent'][0]['train']);
        $this->assertEquals(2.1, $r['recent'][0]['test']);
        $this->assertSame('2m', $r['recent'][0]['params']['tf']);
        $this->assertSame(0, $r['recent'][0]['cand']);
        $this->assertSame('b1', $r['recent'][0]['batch']);

        $this->assertSame(1, $r['today']['challengers'], 'one test-window backtest done today');
        $this->assertSame(1, $r['today']['promotions']);
    }

    public function test_todays_tally_respects_the_utc_midnight_boundary(): void
    {
        config(['desk.strategy' => 'mr', 'desk.perps.active' => ['BTC-USD']]);
        $this->travelTo(Carbon::parse('2026-09-05 00:00:30', 'UTC'));

        $base = ['strategy' => 'mr', 'product_id' => 'BTC-USD', 'side' => 'long', 'train_from' => now()->subDays(30), 'train_to' => now()->subDays(7), 'test_from' => now()->subDays(7), 'test_to' => now(), 'candidates' => 10, 'champion_params' => []];
        // 40 minutes before midnight: yesterday, must not count toward today's tally.
        OptimizerRound::create($base + ['best_train' => 5.0, 'best_test' => 2.0, 'promoted' => true, 'created_at' => Carbon::parse('2026-09-04 23:20:00', 'UTC')]);
        // 20 seconds after midnight: today, must count.
        OptimizerRound::create($base + ['best_train' => 6.0, 'best_test' => 3.0, 'promoted' => true, 'created_at' => Carbon::parse('2026-09-05 00:00:20', 'UTC')]);

        $r = $this->getJson('/api/arena/optimizer?coin=BTC-USD&side=long')->assertOk()->json();

        $this->assertSame(1, $r['today']['promotions'], 'only the post-midnight promotion counts as today');
    }

    public function test_no_champion_yet_reports_nulls_not_an_error(): void
    {
        $r = $this->getJson('/api/arena/optimizer?coin=ETH-USD&side=short')->assertOk()->json();

        $this->assertNull($r['champion']['train']);
        $this->assertNull($r['champion']['test']);
        $this->assertNull($r['champion']['promoted_at']);
        $this->assertSame([], $r['recent']);
        $this->assertSame(0, $r['today']['challengers']);
        $this->assertSame(0, $r['today']['promotions']);
    }

    public function test_side_must_be_long_or_short(): void
    {
        $this->getJson('/api/arena/optimizer?coin=BTC-USD&side=sideways')->assertStatus(422);
    }

    public function test_response_is_cached_for_three_seconds(): void
    {
        config(['desk.strategy' => 'mr', 'desk.perps.active' => ['BTC-USD']]);
        $this->getJson('/api/arena/optimizer?coin=BTC-USD&side=long')->assertOk();

        OptimizerRound::create(['strategy' => 'mr', 'product_id' => 'BTC-USD', 'side' => 'long', 'train_from' => now()->subDays(30), 'train_to' => now()->subDays(7), 'test_from' => now()->subDays(7), 'test_to' => now(), 'candidates' => 5, 'champion_params' => [], 'champion_train' => 9.9, 'champion_test' => 9.9, 'promoted' => false]);

        $r = $this->getJson('/api/arena/optimizer?coin=BTC-USD&side=long')->assertOk()->json();
        $this->assertNull($r['champion']['train'], 'served from the 3s cache, the new round is not reflected yet');
    }
}
