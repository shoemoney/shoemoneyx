<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Contracts\Strategy;
use App\Desk\Desk;
use App\Desk\Exceptions\CycleInProgressException;
use App\Desk\Execution\Executor;
use App\Models\DeskRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeskCycleMutexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);
        Http::fake([
            'api.coinbase.com/api/v3/brokerage/market/products*' => Http::response(['products' => [
                ['product_id' => 'BTC-USD', 'base_currency_id' => 'BTC', 'quote_currency_id' => 'USD', 'product_type' => 'SPOT', 'status' => 'online', 'price' => '80000', 'volume_24h' => '1000', 'price_percentage_change_24h' => '1.5', 'base_increment' => '0.00000001', 'quote_increment' => '0.01'],
            ]]),
            'api.coinbase.com/api/v3/brokerage/market/products/*/ticker*' => Http::response(['best_bid' => '79990', 'best_ask' => '80010', 'trades' => [
                ['trade_id' => '1', 'price' => '80000', 'size' => '0.1', 'side' => 'BUY', 'time' => now()->toIso8601String()],
            ]]),
            'api.coinbase.com/api/v3/brokerage/market/product_book*' => Http::response(['pricebook' => ['bids' => [['price' => '79990', 'size' => '5']], 'asks' => [['price' => '80010', 'size' => '5']]]]),
            'api.coinbase.com/*' => Http::response(['candles' => []]),
        ]);
    }

    public function test_api_cycle_returns_409_and_creates_no_run_while_a_cycle_is_running(): void
    {
        $lock = Cache::lock('desk:cycle:paper', 60);
        $this->assertTrue($lock->get());

        $this->postJson('/api/desk/cycle')
            ->assertStatus(409)
            ->assertExactJson(['ok' => false, 'error' => 'a cycle is already running']);

        $this->assertSame(0, DeskRun::count());
        $lock->release();
    }

    public function test_cycle_runs_normally_and_releases_the_lock(): void
    {
        $this->postJson('/api/desk/cycle')->assertOk()->assertJsonPath('run.status', 'done');
        $this->assertSame(1, DeskRun::count());

        $again = Cache::lock('desk:cycle:paper', 5);
        $this->assertTrue($again->get(), 'lock must be free after a normal cycle');
        $again->release();
    }

    public function test_lock_is_released_when_the_cycle_throws(): void
    {
        $desk = $this->partialMock(Desk::class, function ($mock) {
            $mock->shouldReceive('mode')->andReturn('paper');
            $mock->shouldReceive('strategy')->once()->andThrow(new \RuntimeException('boom'));
        });

        try {
            $desk->cycle();
            $this->fail('expected the cycle to throw');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $again = Cache::lock('desk:cycle:paper', 5);
        $this->assertTrue($again->get(), 'lock must be released after an exception');
        $again->release();
    }

    public function test_second_cycle_is_refused_while_the_first_holds_the_lock(): void
    {
        $lock = Cache::lock('desk:cycle:paper', 60);
        $lock->get();

        $this->expectException(CycleInProgressException::class);
        app(Desk::class)->cycle();
    }

    public function test_console_cycle_skips_quietly_when_locked(): void
    {
        $lock = Cache::lock('desk:cycle:paper', 60);
        $lock->get();

        $this->artisan('desk:cycle')->expectsOutputToContain('cycle skipped')->assertSuccessful();
        $this->assertSame(0, DeskRun::count());
    }

    public function test_executor_is_built_from_the_mode_the_lock_was_taken_for(): void
    {
        $strategy = \Mockery::mock(Strategy::class);
        $executor = \Mockery::mock(Executor::class);
        $this->partialMock(Desk::class, function ($mock) use ($strategy, $executor) {
            // The global mode flips between the lock and the executor lookup.
            $mock->shouldReceive('mode')->andReturn('paper', 'live');
            $mock->shouldReceive('strategy')->andReturn($strategy);
            $mock->shouldReceive('executorForMode')->once()->with('paper')->andReturn($executor);
            $mock->shouldNotReceive('executor');
            $mock->shouldReceive('runCycle')->once()->with($strategy, $executor, null)->andReturn(new DeskRun);
        });

        app(Desk::class)->cycle();
        $this->assertTrue(Cache::lock('desk:cycle:paper', 5)->get());
    }
}
