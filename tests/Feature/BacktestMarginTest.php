<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Backtester;
use App\Models\Candle;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fixtures\FixedTicketStrategy;
use Tests\Feature\Fixtures\TrimAndFundStrategy;
use Tests\Feature\Fixtures\TwoTicketStrategy;
use Tests\TestCase;

/**
 * perps.margin on: entries are gated against (existing notional + the raw proposed ticket) x the
 * overnight initial rate versus the prior step's equity, and every step checks equity against
 * (open notional x maintenance rate), liquidating everything the instant it breaches. Reuses
 * BacktestWholeContractsTest's scaffolding and FixedTicketStrategy fixture.
 */
class BacktestMarginTest extends TestCase
{
    use RefreshDatabase;

    private function setUpProduct(): void
    {
        config(['cache.default' => 'array']);
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);
    }

    public function test_a_short_squeeze_past_maintenance_liquidates_and_the_ticket_that_opened_it_was_allowed(): void
    {
        $this->setUpProduct();
        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        // +3h, not +2h: the simulation window is half-open [from, to) (finding 8), so the bar that
        // actually delivers the squeeze -- the one starting at from+2h -- needs `to` past it to run.
        $to = $from->copy()->addHours(3);

        // bar1 (the entry bar): flat at $1,000. bar2: price spikes to $6,000 -- a short squeeze.
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHour(), 'open' => 1000, 'high' => 1000, 'low' => 1000, 'close' => 1000, 'volume' => 1]);
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHours(2), 'open' => 6000, 'high' => 6000, 'low' => 6000, 'close' => 6000, 'volume' => 1]);

        config(['desk.strategies.fixed_ticket_test' => FixedTicketStrategy::class]);
        $this->app->instance(FixedTicketStrategy::class, new FixedTicketStrategy(2000.0, 'BTC-USD', 'short'));

        $overrides = [
            'paper.slippage_bps' => 0, 'fees.taker_rate' => 0.0002, 'fees.per_contract_usd' => 0.0, 'fees.contract_usd' => 0.0,
            // Zeroed so hourly funding (see BacktestFeesTest) doesn't perturb these exact-equity assertions.
            'fees.funding_hourly_pct' => 0,
            'perps.whole_contracts' => false, 'perps.margin' => true,
        ];

        $bt = app(Backtester::class)->run('fixed_ticket_test', ['BTC-USD'], $from, $to, 10000.0, $overrides);

        // Entry: $2,000 short notional against $0 existing exposure and $10,000 equity.
        // (0 + 2000) * 35% = 700 <= 10,000 -> allowed, no rejection.
        $this->assertSame(0, $bt->stats['margin_rejected']);

        // Margin (finding 10): the entry posts collateral against the FULL $2,000 notional -- qty = 2000
        // / 1000 = 2.0 exactly (the fee is paid separately, never out of qty). margin = 2000 * 35% = 700,
        // fee = 2000 * 0.0002 = 0.4. cash = 10,000 - 700 - 0.4 = 9,299.6.
        // At $6,000: notional_now = 2*6000 = 12,000, maintenance = 12,000*25% = 3,000.
        // posValue = collateral(700) + unrealised(short: 2000 - 2*6000 = -10,000) = -9,300.
        // equity = cash(9,299.6) + posValue(-9,300) = -0.4 <= maintenance(3,000) -> liquidated.
        $this->assertSame(1, $bt->stats['liquidations']);
        $this->assertCount(1, $bt->trades);
        $this->assertSame('liquidation', $bt->trades[0]['rule']);
        $this->assertSame('short', $bt->trades[0]['side']);

        // gross = 2*6000 = 12,000, exit fee = 12,000*0.0002 = 2.4. pnlPart = -(12,000-2,000) - 2.4 = -10,002.4.
        // Margin release on a full close = entry_usd(2,000) * 35% = 700 (not the full $2,000 -- that basis
        // was never in cash to begin with). cash = 9,299.6 + 700 + (-10,002.4) = -2.80. A margin account
        // really can be driven this deeply negative between one hourly maintenance check and the next --
        // this test's whole point is a violent single-bar squeeze, so a step-granularity liquidation model
        // legitimately overshoots the collateral it was protecting.
        $this->assertEqualsWithDelta(-2.80, (float) $bt->ending_equity, 0.01);
        // Net trade pnl (finding 7): the raw price move (-10,000) minus the exit fee (2.4) minus the
        // entry fee (0.4, excluded from entry_usd because margin entries pay it separately) = -10,002.80.
        // This reconciles exactly to cash's total movement on this one trade: -(700+0.4) + (700-10,002.4).
        $this->assertEqualsWithDelta(-10002.80, $bt->trades[0]['pnl_usd'], 1e-2);
    }

    public function test_a_ticket_whose_margin_exceeds_equity_is_rejected_with_no_trade(): void
    {
        $this->setUpProduct();
        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        $to = $from->copy()->addHours(2);
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHour(), 'open' => 1000, 'high' => 1000, 'low' => 1000, 'close' => 1000, 'volume' => 1]);

        config(['desk.strategies.fixed_ticket_test' => FixedTicketStrategy::class]);
        // $500,000 notional * 35% = $175,000 margin needed, against $1,000 starting equity -- rejected
        // before the entry ever touches cash, so the ticket never fills.
        $this->app->instance(FixedTicketStrategy::class, new FixedTicketStrategy(500000.0, 'BTC-USD'));

        $overrides = [
            'paper.slippage_bps' => 0, 'fees.taker_rate' => 0.0002, 'fees.per_contract_usd' => 0.0, 'fees.contract_usd' => 0.0,
            // Zeroed so hourly funding (see BacktestFeesTest) doesn't perturb these exact-equity assertions.
            'fees.funding_hourly_pct' => 0,
            'perps.whole_contracts' => false, 'perps.margin' => true,
        ];

        $bt = app(Backtester::class)->run('fixed_ticket_test', ['BTC-USD'], $from, $to, 1000.0, $overrides);

        $this->assertSame(1, $bt->stats['margin_rejected']);
        $this->assertCount(0, $bt->trades);
        $this->assertEqualsWithDelta(1000.0, (float) $bt->ending_equity, 1e-9, 'no cash moved: the ticket never executed');
    }

    public function test_a_ticket_bigger_than_cash_is_not_clipped_to_it(): void
    {
        $this->setUpProduct();
        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        $to = $from->copy()->addHours(2);
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHour(), 'open' => 1000, 'high' => 1000, 'low' => 1000, 'close' => 1000, 'volume' => 1]);

        config(['desk.strategies.fixed_ticket_test' => FixedTicketStrategy::class]);
        // $20,000 ticket against $10,000 cash: (0 + 20,000) * 35% = $7,000 <= $10,000 equity -> allowed.
        // Execution must post the $7,000 collateral against the FULL $20,000 exposure, never clip the
        // ticket down to the $10,000 on hand the way a cash-notional fill would.
        $this->app->instance(FixedTicketStrategy::class, new FixedTicketStrategy(20000.0));

        $overrides = [
            'paper.slippage_bps' => 0, 'fees.taker_rate' => 0, 'fees.per_contract_usd' => 0.0, 'fees.contract_usd' => 0.0,
            'fees.funding_hourly_pct' => 0, 'perps.whole_contracts' => false, 'perps.margin' => true,
        ];

        $bt = app(Backtester::class)->run('fixed_ticket_test', ['BTC-USD'], $from, $to, 10000.0, $overrides);

        $this->assertSame(0, $bt->stats['margin_rejected']);
        $this->assertCount(1, $bt->trades);
        // usd carries the trade's cost basis (entry_usd + any excluded entry fee); with fees zeroed
        // that is entry_usd itself -- the full $20,000, not the $10,000 a cash-clipped fill would show.
        $this->assertEqualsWithDelta(20000.0, $bt->trades[0]['usd'], 1e-6);
        // Flat price, zero fees: whatever collateral was posted comes back in full at the close, so
        // equity is unchanged either way -- the $20,000 fill only shows up in the trade row itself.
        $this->assertEqualsWithDelta(10000.0, (float) $bt->ending_equity, 1e-6);
    }

    public function test_multiple_margin_positions_reserve_collateral_not_full_notional_between_candidates(): void
    {
        $this->setUpProduct();
        Product::create(['product_id' => 'ETH-USD', 'base_currency' => 'ETH', 'quote_currency' => 'USD']);
        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        $to = $from->copy()->addHours(2);
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHour(), 'open' => 1000, 'high' => 1000, 'low' => 1000, 'close' => 1000, 'volume' => 1]);
        Candle::create(['product_id' => 'ETH-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHour(), 'open' => 1000, 'high' => 1000, 'low' => 1000, 'close' => 1000, 'volume' => 1]);

        config(['desk.strategies.two_ticket_test' => TwoTicketStrategy::class]);
        // Two $12,000 tickets ($24,000 combined) against $10,000 cash: reserving the FULL notional of
        // the first between candidates would starve or clip the second even though both post only
        // $4,200 collateral each ($8,400 combined, comfortably under $10,000).
        $this->app->instance(TwoTicketStrategy::class, new TwoTicketStrategy('BTC-USD', 12000.0, 'ETH-USD', 12000.0));

        $overrides = [
            'paper.slippage_bps' => 0, 'fees.taker_rate' => 0, 'fees.per_contract_usd' => 0.0, 'fees.contract_usd' => 0.0,
            'fees.funding_hourly_pct' => 0, 'perps.whole_contracts' => false, 'perps.margin' => true,
        ];

        $bt = app(Backtester::class)->run('two_ticket_test', ['BTC-USD', 'ETH-USD'], $from, $to, 10000.0, $overrides);

        $this->assertSame(0, $bt->stats['margin_rejected']);
        $this->assertCount(2, $bt->trades, 'both tickets filled in full — neither was clipped or skipped');
        $this->assertEqualsWithDelta(12000.0, $bt->trades[0]['usd'], 1e-6);
        $this->assertEqualsWithDelta(12000.0, $bt->trades[1]['usd'], 1e-6);
    }

    public function test_a_margin_trim_releases_only_its_share_of_collateral(): void
    {
        $this->setUpProduct();
        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        $to = $from->copy()->addHours(3);

        // bar1 (entry): flat at $1,000. bar2: closes at $1,100 -- the rung is set exactly at the close
        // (a 1H-step backtest's "filled at rung" check only compares against the bar's close, not its
        // high/low extremes -- those are only tracked for sub-hour steps -- so a rung above close never
        // registers as touched; matching it exactly is how BacktestFeesTest's own rung test stays valid
        // under a 1H step too).
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHour(), 'open' => 1000, 'high' => 1000, 'low' => 1000, 'close' => 1000, 'volume' => 1]);
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->addHours(2), 'open' => 1000, 'high' => 1100, 'low' => 1000, 'close' => 1100, 'volume' => 1]);

        config(['desk.strategies.trim_fund_test' => TrimAndFundStrategy::class]);
        $this->app->instance(TrimAndFundStrategy::class, new TrimAndFundStrategy(8000.0, 'BTC-USD', 'long', trimOnCall: 2, trimFraction: 0.5, trimLimitPrice: 1100.0));

        $overrides = [
            'paper.slippage_bps' => 0, 'fees.taker_rate' => 0, 'fees.maker_rate' => 0,
            'fees.per_contract_usd' => 0.0, 'fees.contract_usd' => 0.0, 'fees.funding_hourly_pct' => 0,
            'perps.whole_contracts' => false, 'perps.margin' => true,
        ];

        $bt = app(Backtester::class)->run('trim_fund_test', ['BTC-USD'], $from, $to, 10000.0, $overrides);

        // Entry: qty = 8000/1000 = 8, margin = 8000*35% = 2,800. cash = 10,000 - 2,800 = 7,200.
        // Trim (50% at the $1,100 rung): qty sold = 4, gross = 4,400, cost sold = 4,000, pnl = 400.
        // Collateral released = the trimmed half of the posted margin = 2,800 * 50% = 1,400 (not the
        // full $4,000 cost sold -- that was never taken out of cash to begin with). cash after the trim
        // = 7,200 + 1,400 + 400 = 9,000 (not directly observable mid-run, but ending_equity below is the
        // trim's 9,000 plus the final leg, so a wrong release here throws that final number off too).
        $this->assertSame(1, $bt->stats['trims']);

        // End-of-test liquidates the remaining 4 units, still at $1,100 (price never moved again):
        // gross = 4,400, cost = 4,000, pnl = 400. The other half of the collateral (1,400) releases in
        // full. ending_equity = 9,000 + 1,400 + 400 = 10,800 -- +$800 total, exactly the $800 the
        // position's full 8-unit round trip earned on price alone (1,000 -> 1,100), proving no collateral
        // was stranded or double-released across splitting one position into two release events.
        $this->assertEqualsWithDelta(10800.0, (float) $bt->ending_equity, 1e-6);

        // The position's one recorded trade row (the trim didn't fully close it, so only the final
        // close is a trade row) carries the net pnl from BOTH legs via realised_usd: 400 + 400 = 800.
        $this->assertCount(1, $bt->trades);
        $this->assertEqualsWithDelta(800.0, $bt->trades[0]['pnl_usd'], 1e-6);
    }
}
