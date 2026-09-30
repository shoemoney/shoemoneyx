<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Desk;
use App\Exchange\Coinbase\CoinbaseMarketData;
use App\Exchange\Contracts\MarketData;
use App\Models\DeskEvent;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Fixtures\TwoEntryHaltStrategy;
use Tests\TestCase;

/** The cycle lease is only safe if the cycle stops starting entries well before it can lapse. */
class DeskCycleDeadlineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['cache.default' => 'array', 'desk.mode' => 'paper', 'desk.strategies.two_entry_halt_test' => TwoEntryHaltStrategy::class, 'desk.strategy' => 'two_entry_halt_test']);
        $this->app->bind(MarketData::class, fn () => new class extends CoinbaseMarketData
        {
            public function ticker(string $productId, int $limit = 100): array
            {
                return ['best_bid' => 100.0, 'best_ask' => 100.0, 'trades' => []];
            }

            public function book(string $productId, int $depth = 50): array
            {
                return ['bids' => [[99.0, 1000.0]], 'asks' => [[101.0, 1000.0]], 'ts' => time()];
            }

            public function healthy(): bool
            {
                return true;
            }
        });
        $this->app->instance(TwoEntryHaltStrategy::class, new TwoEntryHaltStrategy(usd: 100.0, productIds: ['BTC-USD', 'ETH-USD']));
    }

    public function test_spent_budget_stops_new_entries_and_is_logged(): void
    {
        config(['desk.cycle_budget_seconds' => 0]);

        $run = app(Desk::class)->cycle();

        $this->assertSame('done', $run->status);
        $this->assertSame(2, $run->candidates);
        $this->assertSame(0, $run->filled);
        $this->assertSame(0, Position::count());
        $this->assertTrue(DeskEvent::where('level', 'warn')->where('message', 'like', 'cycle budget%')->exists());
    }

    public function test_invalid_budget_env_falls_back_to_the_default_and_warns(): void
    {
        config(['desk.cycle_budget_seconds' => '1200s']);

        $run = app(Desk::class)->cycle();

        $this->assertSame(2, $run->filled, 'a garbage budget must not skip every candidate');
        $this->assertTrue(DeskEvent::where('level', 'warn')->where('message', 'like', 'DESK_CYCLE_BUDGET_SECONDS%')->exists());
    }

    public function test_default_budget_leaves_the_cycle_untouched(): void
    {
        $run = app(Desk::class)->cycle();

        $this->assertSame(2, $run->filled);
        $this->assertFalse(DeskEvent::where('message', 'like', 'cycle budget%')->exists());
    }
}
