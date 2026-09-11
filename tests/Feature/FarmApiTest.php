<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Backtest;
use App\Models\OptimizerRound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class FarmApiTest extends TestCase
{
    use RefreshDatabase;

    private ?string $watchLogPath = null;

    private ?string $watchLogBackup = null;

    protected function tearDown(): void
    {
        if ($this->watchLogPath !== null) {
            if ($this->watchLogBackup !== null) {
                file_put_contents($this->watchLogPath, $this->watchLogBackup);
            } else {
                @unlink($this->watchLogPath);
            }
        }
        parent::tearDown();
    }

    /** @return array<int, array<string, string>> */
    private function clientListRows(): array
    {
        $rows = [];
        for ($i = 0; $i < 4; $i++) {
            $rows[] = ['addr' => "192.168.1.3:510{$i}", 'name' => 'worker'];
        }
        for ($i = 0; $i < 2; $i++) {
            $rows[] = ['addr' => "127.0.0.1:520{$i}", 'name' => ''];
        }
        $rows[] = ['addr' => '192.168.1.99:5300', 'name' => ''];

        return $rows;
    }

    private function mockRedis(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after', 4000);
        $now = now()->getTimestamp();

        $mock = Mockery::mock();
        $mock->shouldReceive('command')->with('client', ['list'])->once()->andReturn($this->clientListRows());
        $mock->shouldReceive('llen')->andReturnUsing(fn ($key) => $key === 'queues:backtests' ? 0 : 5);
        $mock->shouldReceive('zcard')->andReturnUsing(fn ($key) => match ($key) {
            'queues:backtests:reserved' => 68,
            'queues:backtests:delayed' => 0,
            'queues:backtests-light:reserved' => 3,
            'queues:backtests-light:delayed' => 1,
            default => 0,
        });
        $mock->shouldReceive('zrange')->andReturnUsing(function ($key) use ($now, $retryAfter) {
            return match ($key) {
                'queues:backtests:reserved' => [json_encode(['job' => 1]) => $now - 412 + $retryAfter],
                'queues:backtests-light:reserved' => [json_encode(['job' => 2]) => $now - 100 + $retryAfter],
                default => [],
            };
        });

        Redis::shouldReceive('connection')->andReturn($mock);
    }

    private function writeWatchLog(string $contents): void
    {
        $this->watchLogPath = storage_path('logs/rounds-watch.log');
        $this->watchLogBackup = is_file($this->watchLogPath) ? file_get_contents($this->watchLogPath) : null;
        file_put_contents($this->watchLogPath, $contents);
    }

    public function test_farm_snapshot_reports_hosts_queues_throughput_optimizers_and_watch(): void
    {
        $hosts = ['192.168.1.5' => ['label' => 'desk', 'role' => 'desk'], '192.168.1.4' => ['label' => 'mac-b', 'role' => 'mac'], '192.168.1.3' => ['label' => 'mac-a', 'role' => 'mac']];
        for ($i = 10; $i <= 19; $i++) {
            $hosts["192.168.1.{$i}"] = ['label' => "pi-{$i}", 'role' => 'pi'];
        }
        config(['farm.hosts' => $hosts, 'farm.expected_workers' => ['192.168.1.5' => 24, '192.168.1.4' => 16, '192.168.1.3' => 24, 'default' => 4]]);
        $this->mockRedis();

        Backtest::create(['strategy' => 'mr', 'status' => 'done', 'products' => ['BTC-USD'], 'from' => now()->subDay(), 'to' => now(), 'starting_cash' => 10000, 'stats' => ['under_one_contract' => 3], 'under_one_contract' => 3, 'updated_at' => now(), 'completed_at' => now()]);
        Backtest::create(['strategy' => 'mr', 'status' => 'done', 'products' => ['ETH-USD'], 'from' => now()->subDay(), 'to' => now(), 'starting_cash' => 10000, 'stats' => ['under_one_contract' => 2], 'under_one_contract' => 2, 'updated_at' => now()->subMinutes(3), 'completed_at' => now()->subMinutes(3)]);
        Backtest::create(['strategy' => 'mr', 'status' => 'running', 'products' => ['SOL-USD'], 'from' => now()->subDay(), 'to' => now(), 'starting_cash' => 10000, 'updated_at' => now()]);
        Backtest::create(['strategy' => 'mr', 'status' => 'error', 'products' => ['SOL-USD'], 'from' => now()->subDay(), 'to' => now(), 'starting_cash' => 10000, 'error' => 'boom', 'updated_at' => now()]);

        $base = ['strategy' => 'mr', 'product_id' => 'BTC-USD', 'train_from' => now()->subDays(30), 'train_to' => now()->subDays(7), 'test_from' => now()->subDays(7), 'test_to' => now(), 'candidates' => 97, 'champion_params' => []];
        OptimizerRound::create($base + ['side' => 'long', 'cash' => 10000, 'promoted' => true, 'created_at' => now()->subMinutes(5)]);
        OptimizerRound::create($base + ['side' => 'short', 'cash' => 10000, 'promoted' => false, 'created_at' => now()->subMinutes(4)]);
        OptimizerRound::create($base + ['side' => 'long', 'cash' => 3000, 'promoted' => false, 'created_at' => now()->subMinutes(3)]);

        $this->writeWatchLog(implode("\n", [
            '[PASS] fresh-round: an optimizer round landed in the last 30m',
            '[PASS] workers-alive: skipped under unit tests',
            '[FAIL] stuck-backtests: 2 backtests stuck in running > 90m',
            '',
            '[PASS] fresh-round: an optimizer round landed in the last 30m',
            '[PASS] workers-alive: 68 reserved+queued, 24 workers connected',
            '[PASS] stuck-backtests: no backtests stuck in running > 90m',
            '',
        ]));

        $data = $this->getJson('/api/farm')->assertOk()->json();

        $hosts = collect($data['hosts'])->keyBy('ip');
        $this->assertCount(14, $hosts, '13 configured hosts plus the one unknown ip');
        $this->assertSame(4, $hosts['192.168.1.3']['connections']);
        $this->assertSame(2, $hosts['192.168.1.3']['workers_est'], '4 conns / 2 per worker');
        $this->assertSame(2, $hosts['192.168.1.5']['connections'], 'the loopback pair counts toward the desk host');
        $this->assertSame(0, $hosts['192.168.1.4']['connections'], 'a dark host is still listed');
        $unknown = collect($data['hosts'])->firstWhere('ip', '192.168.1.99');
        $this->assertSame('unknown', $unknown['role']);
        $this->assertSame(1, $unknown['connections']);

        $this->assertSame(0, $data['queues']['backtests']['queued']);
        $this->assertSame(68, $data['queues']['backtests']['reserved']);
        $this->assertEqualsWithDelta(412, $data['queues']['backtests']['oldest_reserved_s'], 1, 'the mock stamps the reservation from time() and the controller reads the clock again');
        $this->assertSame(5, $data['queues']['backtests-light']['queued']);
        $this->assertSame(3, $data['queues']['backtests-light']['reserved']);
        $this->assertSame(1, $data['queues']['backtests-light']['delayed']);
        $this->assertSame(100, $data['queues']['backtests-light']['oldest_reserved_s']);

        $this->assertSame(1, $data['throughput']['done_1m']);
        $this->assertSame(2, $data['throughput']['done_5m']);
        $this->assertSame(2, $data['throughput']['computed_5m'], 'neither done row carries a cached_from marker');
        $this->assertSame(0, $data['throughput']['reused_5m']);
        $this->assertSame(1, $data['throughput']['running']);
        $this->assertSame(1, $data['throughput']['failed_1h']);
        $this->assertSame(5, $data['throughput']['under_one_contract_5m']);

        $names = collect($data['optimizers'])->pluck('name');
        $this->assertContains('shoemoneyx-optimizer', $names);
        $this->assertContains('shoemoneyx-optimizer-short', $names);
        $this->assertContains('shoemoneyx-optimizer-small', $names);

        $this->assertNotNull($data['watch']['at']);
        $this->assertSame([
            '[PASS] fresh-round: an optimizer round landed in the last 30m',
            '[PASS] workers-alive: 68 reserved+queued, 24 workers connected',
            '[PASS] stuck-backtests: no backtests stuck in running > 90m',
        ], $data['watch']['lines'], 'only the last run block, not the earlier failing one');
    }

    public function test_snapshot_is_cached_for_five_seconds(): void
    {
        $this->mockRedis();

        $this->getJson('/api/farm')->assertOk();
        $this->getJson('/api/farm')->assertOk();
        // The `command` expectation above is ->once(): a second request inside the 5s cache
        // window must be served from Cache::remember without touching Redis again.
        $this->addToAssertionCount(1);
    }

    public function test_missing_watch_log_reports_null_and_empty(): void
    {
        $this->mockRedis();
        $path = storage_path('logs/rounds-watch.log');
        if (is_file($path)) {
            $this->watchLogPath = $path;
            $this->watchLogBackup = file_get_contents($path);
            unlink($path);
        }

        $data = $this->getJson('/api/farm')->assertOk()->json();

        $this->assertNull($data['watch']['at']);
        $this->assertSame([], $data['watch']['lines']);
    }

    /** @return array<string, mixed> */
    private function backtestAttrs(array $overrides = []): array
    {
        return array_merge([
            'strategy' => 'mr', 'status' => 'done', 'products' => ['BTC-USD'],
            'from' => now()->subDay(), 'to' => now(), 'starting_cash' => 10000,
        ], $overrides);
    }

    public function test_throughput_counts_only_rows_completed_in_window_and_ignores_bare_updated_at_movement(): void
    {
        $this->mockRedis();

        // Finished 10 minutes ago; retention touched it moments ago while stripping the equity
        // curve — updated_at moved but completed_at, the real finish time, did not.
        Backtest::create($this->backtestAttrs([
            'under_one_contract' => 9, 'completed_at' => now()->subMinutes(10), 'updated_at' => now(),
        ]));
        // Genuinely completed inside the 5-minute window.
        Backtest::create($this->backtestAttrs([
            'under_one_contract' => 4, 'completed_at' => now(), 'updated_at' => now(),
        ]));

        $data = $this->getJson('/api/farm')->assertOk()->json();

        $this->assertSame(1, $data['throughput']['done_5m'], 'the stale-but-touched row must not count as fresh throughput');
        $this->assertSame(1, $data['throughput']['done_1m']);
        $this->assertSame(4, $data['throughput']['under_one_contract_5m'], 'the scalar sum only covers the row actually completed in-window');
    }

    public function test_under_one_contract_total_matches_scalar_column_sum(): void
    {
        $this->mockRedis();

        Backtest::create($this->backtestAttrs(['under_one_contract' => 7, 'completed_at' => now()]));
        Backtest::create($this->backtestAttrs(['under_one_contract' => 5, 'completed_at' => now()->subMinutes(2)]));
        // Outside the 5-minute window — must not be folded into the sum.
        Backtest::create($this->backtestAttrs(['under_one_contract' => 100, 'completed_at' => now()->subMinutes(10)]));

        $expected = Backtest::where('status', 'done')->where('completed_at', '>=', now()->subMinutes(5))->sum('under_one_contract');
        $this->assertSame(12, $expected, 'sanity: the scalar column itself sums to 12 for the in-window rows');

        $data = $this->getJson('/api/farm')->assertOk()->json();

        $this->assertSame($expected, $data['throughput']['under_one_contract_5m']);
    }

    public function test_throughput_reports_computed_and_reused_counts_separately(): void
    {
        $this->mockRedis();

        Backtest::create($this->backtestAttrs(['completed_at' => now(), 'params' => ['a' => 1]]));
        Backtest::create($this->backtestAttrs(['completed_at' => now(), 'params' => ['a' => 1, '_opt' => ['cached_from' => 42]]]));

        $data = $this->getJson('/api/farm')->assertOk()->json();

        $this->assertSame(2, $data['throughput']['done_5m']);
        $this->assertSame(1, $data['throughput']['computed_5m']);
        $this->assertSame(1, $data['throughput']['reused_5m']);
    }

    public function test_optimizers_report_last_round_at_per_group_including_a_null_tag(): void
    {
        $this->mockRedis();

        $base = ['strategy' => 'mr', 'product_id' => 'BTC-USD', 'train_from' => now()->subDays(30), 'train_to' => now()->subDays(7), 'test_from' => now()->subDays(7), 'test_to' => now(), 'candidates' => 97, 'champion_params' => []];
        OptimizerRound::create($base + ['side' => 'long', 'cash' => 10000, 'tag' => null, 'promoted' => false, 'created_at' => now()->subMinutes(10)]);
        OptimizerRound::create($base + ['side' => 'long', 'cash' => 10000, 'tag' => null, 'promoted' => true, 'created_at' => now()->subMinutes(2)]);
        OptimizerRound::create($base + ['side' => 'long', 'cash' => 10000, 'tag' => 'nightly', 'promoted' => false, 'created_at' => now()->subMinute()]);

        $data = $this->getJson('/api/farm')->assertOk()->json();
        $groups = collect($data['optimizers']);

        $nullTagGroup = $groups->first(fn ($g) => $g['tag'] === null && $g['side'] === 'long' && (float) $g['cash'] === 10000.0);
        $this->assertNotNull($nullTagGroup, 'the null-tag group must still be present and grouped correctly');
        $this->assertSame(2, $nullTagGroup['rounds_1h']);
        $this->assertSame(1, $nullTagGroup['promoted_1h']);
        $this->assertNotNull($nullTagGroup['last_round_at']);
        $this->assertEqualsWithDelta(now()->subMinutes(2)->getTimestamp(), Carbon::parse($nullTagGroup['last_round_at'])->getTimestamp(), 2);

        $taggedGroup = $groups->first(fn ($g) => $g['tag'] === 'nightly');
        $this->assertNotNull($taggedGroup, 'a tagged group must be reported separately from the null-tag group');
        $this->assertSame(1, $taggedGroup['rounds_1h']);
        $this->assertEqualsWithDelta(now()->subMinute()->getTimestamp(), Carbon::parse($taggedGroup['last_round_at'])->getTimestamp(), 2);
    }

    public function test_snapshot_db_query_count_is_bounded_regardless_of_optimizer_group_count(): void
    {
        $this->mockRedis();

        $base = ['strategy' => 'mr', 'product_id' => 'BTC-USD', 'train_from' => now()->subDays(30), 'train_to' => now()->subDays(7), 'test_from' => now()->subDays(7), 'test_to' => now(), 'candidates' => 97, 'champion_params' => []];
        for ($i = 0; $i < 12; $i++) {
            OptimizerRound::create($base + [
                'side' => $i % 2 === 0 ? 'long' : 'short', 'cash' => 10000, 'tag' => "tag{$i}",
                'promoted' => false, 'created_at' => now()->subMinutes($i),
            ]);
        }
        Backtest::create($this->backtestAttrs(['under_one_contract' => 1, 'completed_at' => now()]));

        DB::enableQueryLog();
        $this->getJson('/api/farm')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(6, count($queries), 'the snapshot must stay O(1) DB queries regardless of how many optimizer groups exist: '.json_encode(array_column($queries, 'query')));
    }
}
