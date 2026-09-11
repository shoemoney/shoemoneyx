<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Backtester;
use App\Models\Candle;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fixtures\TwoTicketStrategy;
use Tests\TestCase;

/**
 * A candidate that already cleared VET and SIZE can still never become a fill: by the time its
 * ticket reaches the execute step, an earlier candidate queued in the SAME bar (TwoTicketStrategy
 * fires both A and B from one SCAN) may have already spent the cash it needed. That is a distinct
 * failure mode from sized_zero (SIZE itself said zero dollars) or margin_rejected (a margin
 * collateral gate) -- it is a fully-sized ticket clipped to nothing by min($order['usd'], $cash) at
 * fill time. skipped_capital counts it.
 */
class BacktestSkippedCapitalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_candidate_clipped_by_free_cash_increments_skipped_capital(): void
    {
        config(['cache.default' => 'array']);
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);
        Product::create(['product_id' => 'ETH-USD', 'base_currency' => 'ETH', 'quote_currency' => 'USD']);

        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        $to = $from->copy()->addHours(2);
        foreach (['BTC-USD', 'ETH-USD'] as $pid) {
            Candle::create([
                'product_id' => $pid, 'timeframe' => '1H', 'candle_start' => $from->copy()->addHour(),
                'open' => 100, 'high' => 100, 'low' => 100, 'close' => 100, 'volume' => 1,
            ]);
        }

        config(['desk.strategies.two_ticket_test' => TwoTicketStrategy::class]);
        // A's $100 ticket spends the entire $100 starting cash first (pending fills execute in the
        // order SCAN proposed them); B's $50 ticket then hits min(50, 0) = 0 and never fills.
        $this->app->instance(TwoTicketStrategy::class, new TwoTicketStrategy('BTC-USD', 100.0, 'ETH-USD', 50.0));

        $overrides = [
            'paper.slippage_bps' => 0,
            'fees.taker_rate' => 0.0,
            'perps.whole_contracts' => false,
            'perps.margin' => false,
        ];

        $bt = app(Backtester::class)->run('two_ticket_test', ['BTC-USD', 'ETH-USD'], $from, $to, 100.0, $overrides);

        $this->assertCount(1, $bt->trades, 'only A should have filled -- B was clipped to nothing');
        $this->assertSame('BTC-USD', $bt->trades[0]['product']);
        $this->assertSame(1, $bt->stats['skipped_capital'], 'B\'s zero-cash clip must be counted');
        $this->assertSame(0, $bt->stats['sized_zero'], 'SIZE itself returned a positive ticket for B -- this is not a sized_zero case');
        $this->assertSame(0, $bt->stats['margin_rejected'], 'no margin in play -- this is not a margin_rejected case');
    }
}
