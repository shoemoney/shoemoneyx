<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Arena\ArenaRunner;
use App\Models\ArenaSeat;
use App\Models\Candle;
use App\Models\Fill;
use App\Models\PaperLedger;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Fixtures\AlwaysEnterStrategy;
use Tests\TestCase;

/**
 * ArenaRunner::cycle() against a real (faked-exchange) SCAN -> VET -> SIZE -> FILLS -> RISK
 * pass, proving Desk::forSeat()'s scoping actually isolates each seat's own account rather than
 * just compiling. Uses the AlwaysEnterStrategy test double so the assertion is about the
 * arena's plumbing, not about coaxing a real strategy's statistical signal to fire.
 */
class ArenaMultiSeatCycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'desk.mode' => 'paper',
            'desk.perps.enabled' => false, // this suite's fixed $100 test size is under one BTC-PERP contract
            'desk.strategies.always_enter' => AlwaysEnterStrategy::class,
        ]);
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
        $this->artisan('market:sync-products')->assertSuccessful();
        $t = now()->startOfHour();
        foreach (range(0, 47) as $i) {
            Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $t->copy()->subHours(47 - $i), 'open' => 80000, 'high' => 80100, 'low' => 79900, 'close' => 80050, 'volume' => 10]);
        }
    }

    public function test_two_seats_on_one_cycle_produce_independent_fills_positions_and_cash(): void
    {
        $seatA = ArenaSeat::create(['label' => 'Seat A', 'strategy_key' => 'always_enter', 'starting_cash' => 1000, 'status' => 'active', 'started_at' => now()]);
        $seatB = ArenaSeat::create(['label' => 'Seat B', 'strategy_key' => 'always_enter', 'starting_cash' => 500, 'status' => 'active', 'started_at' => now()]);

        $runs = app(ArenaRunner::class)->cycle();

        $this->assertCount(2, $runs);

        $posA = Position::mode('paper')->seat($seatA->id)->get();
        $posB = Position::mode('paper')->seat($seatB->id)->get();
        $this->assertCount(1, $posA);
        $this->assertCount(1, $posB);
        $this->assertNotSame($posA->first()->id, $posB->first()->id, 'each seat gets its own position row');

        $fillsA = Fill::seat($seatA->id)->where('status', 'filled')->get();
        $fillsB = Fill::seat($seatB->id)->where('status', 'filled')->get();
        $this->assertCount(1, $fillsA);
        $this->assertCount(1, $fillsB);

        // Cash moved independently, off each seat's own starting_cash — not the same ledger.
        $cashA = (float) PaperLedger::seat($seatA->id)->sum('amount');
        $cashB = (float) PaperLedger::seat($seatB->id)->sum('amount');
        $this->assertEqualsWithDelta(1000 - 100, $cashA, 1.0);
        $this->assertEqualsWithDelta(500 - 100, $cashB, 1.0);
        $this->assertNotEquals($cashA, $cashB);

        // The main desk's own (seat-less) account is untouched.
        $this->assertSame(0, Position::whereNull('arena_seat_id')->count());
        $this->assertSame(0, Fill::whereNull('arena_seat_id')->where('status', 'filled')->count());
        $this->assertSame(0, (int) PaperLedger::whereNull('arena_seat_id')->count());
    }

    public function test_retire_stops_a_seat_from_trading_on_the_next_cycle(): void
    {
        $seat = ArenaSeat::create(['label' => 'Retired', 'strategy_key' => 'always_enter', 'starting_cash' => 1000, 'status' => 'retired', 'started_at' => now(), 'stopped_at' => now()]);

        $runs = app(ArenaRunner::class)->cycle();

        $this->assertSame([], $runs, 'a retired seat is never picked up by the runner');
        $this->assertSame(0, Position::seat($seat->id)->count());
        $this->assertSame(0, Fill::seat($seat->id)->count());
    }
}
