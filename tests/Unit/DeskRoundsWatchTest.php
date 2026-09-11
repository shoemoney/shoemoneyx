<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\OptimizerRound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class DeskRoundsWatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_fails_when_no_round_has_landed_recently_and_passes_once_one_has(): void
    {
        config(['desk.perps.active' => []]);
        DB::table('optimizer_rounds')->insert([
            'product_id' => 'BTC-USD',
            'strategy' => 'mr',
            'side' => 'long',
            'train_from' => now()->subDays(30),
            'train_to' => now()->subDays(7),
            'test_from' => now()->subDays(7),
            'test_to' => now(),
            'candidates' => 97,
            'created_at' => now()->subMinutes(40),
            'updated_at' => now()->subMinutes(40),
        ]);

        $this->artisan('desk:rounds-watch')->assertExitCode(1);

        DB::table('optimizer_rounds')->insert([
            'product_id' => 'BTC-USD',
            'strategy' => 'mr',
            'side' => 'long',
            'train_from' => now()->subDays(30),
            'train_to' => now()->subDays(7),
            'test_from' => now()->subDays(7),
            'test_to' => now(),
            'candidates' => 97,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        // (b) is skipped under app()->runningUnitTests() (see DeskRoundsWatch::checkWorkersAlive)
        // so this only exercises (a) and (c), both of which pass with nothing stuck.
        $this->artisan('desk:rounds-watch')->assertExitCode(0);
    }

    public function test_fails_and_remediates_when_a_backtest_is_stuck_running(): void
    {
        config(['desk.perps.active' => []]);
        DB::table('optimizer_rounds')->insert([
            'product_id' => 'BTC-USD',
            'strategy' => 'mr',
            'side' => 'long',
            'train_from' => now()->subDays(30),
            'train_to' => now()->subDays(7),
            'test_from' => now()->subDays(7),
            'test_to' => now(),
            'candidates' => 97,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        DB::table('backtests')->insert([
            'strategy' => 'mr',
            'status' => 'running',
            'products' => json_encode(['BTC-USD']),
            'from' => now()->subDays(30),
            'to' => now(),
            'starting_cash' => 10000,
            'created_at' => now()->subMinutes(130),
            'updated_at' => now()->subMinutes(120),
        ]);

        // desk:release-orphans talks to Redis directly (not the queue facade), so without a fake
        // it hit an unavailable connection in CI and silently took the caught-error branch
        // instead of the real "requeue this reservation" path — see the code review's testing
        // gaps section. Fake one orphaned reservation (taken well before the 90m/retry_after
        // cutoff) and assert it's actually moved: zrem'd off "reserved" and rpush'd back onto the
        // live queue with attempts reset, which is the real recovery state transition.
        $orphanPayload = json_encode(['job' => 'stuck', 'attempts' => 3]);
        $connection = Mockery::mock();
        $connection->shouldReceive('zrange')
            ->with('queues:backtests:reserved', 0, -1, ['withscores' => true])
            ->andReturn([$orphanPayload => time() - 10000]);
        $connection->shouldReceive('zrange')
            ->with('queues:backtests-light:reserved', 0, -1, ['withscores' => true])
            ->andReturn([]);
        $connection->shouldReceive('zrem')->once()->with('queues:backtests:reserved', $orphanPayload);
        $connection->shouldReceive('rpush')->once()->with('queues:backtests', Mockery::on(function (string $json) {
            return (json_decode($json, true)['attempts'] ?? null) === 0;
        }));
        Redis::shouldReceive('connection')->andReturn($connection);

        $this->artisan('desk:rounds-watch')
            ->assertExitCode(1)
            ->expectsOutputToContain('stuck in running > 90m')
            ->expectsOutputToContain('reserved');
    }

    public function test_fails_when_an_active_coin_side_has_no_recent_round(): void
    {
        config(['desk.perps.active' => ['BTC-USD']]);
        $round = ['product_id' => 'BTC-USD', 'strategy' => 'mr', 'train_from' => now()->subDays(30), 'train_to' => now()->subDays(7), 'test_from' => now()->subDays(7), 'test_to' => now(), 'candidates' => 1, 'created_at' => now()->subMinutes(5)];
        OptimizerRound::create($round + ['side' => 'long']);

        $code = Artisan::call('desk:rounds-watch', ['--minutes' => 30, '--coin-minutes' => 120]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('[FAIL] stale-coins: 1 coin/sides without a round in 120m: BTC-USD short', Artisan::output());

        OptimizerRound::create($round + ['side' => 'short']);

        $code = Artisan::call('desk:rounds-watch', ['--minutes' => 30, '--coin-minutes' => 120]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('[PASS] stale-coins', Artisan::output());
    }
}
