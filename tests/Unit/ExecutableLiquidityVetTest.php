<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Data\Bank;
use App\Desk\Data\Candidate;
use App\Desk\Data\ProductStats;
use App\Desk\Data\Verdict;
use App\Desk\DeskContext;
use App\Desk\Strategies\CustomStrategy;
use Tests\TestCase;

/**
 * Suggestion 8 (2026-09-05 short-entry review) / suggestion 6 (trade-entry review): side-aware
 * executable liquidity. A long ENTRY is approved against ASKS with a conservative same-size check
 * of BIDS (the eventual exit); a short ENTRY is approved against BIDS with a same-size check of
 * ASKS (the eventual cover). Missing/stale depth is REJECTED as unavailable whenever the gate is
 * enabled — never a silent pass — except backtests, which skip it explicitly and count the skip.
 */
class ExecutableLiquidityVetTest extends TestCase
{
    /** Exposes the protected gate directly, for tests that assert the actual-notional parameter is what drives the outcome. */
    private function strategy(): CustomStrategy
    {
        return new class extends CustomStrategy
        {
            public function gate(Candidate $c, DeskContext $ctx, float $notionalUsd): array
            {
                return $this->vetExecutableLiquidity($c, $ctx, $notionalUsd);
            }
        };
    }

    /** A neutral ctx that isolates the executable-liquidity gate from every other VET check. */
    private function ctx(array $overrides = [], bool $backtest = false): DeskContext
    {
        $s = new CustomStrategy;
        $params = array_replace_recursive($s->defaults(), [
            'vet' => [
                'max_spread_bps' => 1000, 'min_volume_surge_h1' => 0, 'max_price_change_h1_pct' => 1000,
                'max_breakeven_move_pct' => 1000, 'max_pct_of_volume_24h' => 100, 'min_book_depth_multiple' => 0,
                'min_candles_h1' => 0,
            ],
            'scan' => ['one_buyer_price_pct' => 1000, 'min_buy_share' => 0, 'min_age_hours' => 0],
            'fees' => ['taker_rate' => 0, 'floor_usd' => 0],
            'paper' => ['slippage_bps' => 0],
        ], $overrides);

        return new DeskContext($params, 'paper', false, [], new \DateTimeImmutable('2026-09-05 12:00:00'), $backtest);
    }

    private function bank(): Bank
    {
        return new Bank(1.0, 0, 0.2, 0, 0); // equity ~1 -> intendedTicket() collapses to size.min_ticket_usd
    }

    private function stats(array $overrides): ProductStats
    {
        return ProductStats::fromArray($overrides + [
            'product_id' => 'TEST-USD', 'price' => 100.0, 'spread_bps' => 5, 'candles_h1_count' => 48,
            'volume_h24_usd' => 10_000_000, 'age_hours' => null,
        ]);
    }

    private function deepBidsThinAsks(): ProductStats
    {
        // Selling (short entry, or a long's exit) into bids finds a deep book; buying (a short's
        // cover, or a long entry) against asks finds only $500.50 total.
        return $this->stats([
            'bid_depth_usd' => 99_900_000.0, 'ask_depth_usd' => 500.5, 'quote_age_sec' => 1,
            'book_levels' => ['bids' => [[99.9, 1_000_000.0]], 'asks' => [[100.1, 5.0]], 'ref_price' => 100.0],
        ]);
    }

    private function thinBidsDeepAsks(): ProductStats
    {
        return $this->stats([
            'bid_depth_usd' => 499.5, 'ask_depth_usd' => 99_900_000.0, 'quote_age_sec' => 1,
            'book_levels' => ['bids' => [[99.9, 5.0]], 'asks' => [[100.1, 1_000_000.0]], 'ref_price' => 100.0],
        ]);
    }

    public function test_deep_bids_thin_asks_approves_a_small_short_entry(): void
    {
        $c = new Candidate($this->deepBidsThinAsks(), 1.0, 'test', side: 'short');
        $v = (new CustomStrategy)->vet($c, $this->bank(), $this->ctx(['size' => ['min_ticket_usd' => 100]]));

        $this->assertSame(Verdict::PASS, $v->verdict, $v->why);
    }

    public function test_deep_bids_thin_asks_rejects_a_short_entry_when_the_cover_check_fails(): void
    {
        $c = new Candidate($this->deepBidsThinAsks(), 1.0, 'test', side: 'short');
        $v = (new CustomStrategy)->vet($c, $this->bank(), $this->ctx(['size' => ['min_ticket_usd' => 600]]));

        $this->assertSame('executable_liquidity', $v->failedCheck);
        $this->assertStringContainsString('same-size', $v->why, 'the rejection must name the cover check, not the entry');
        $this->assertStringContainsString('BUY', $v->why);
    }

    public function test_thin_bids_deep_asks_approves_a_small_long_entry(): void
    {
        $c = new Candidate($this->thinBidsDeepAsks(), 1.0, 'test'); // default side = long
        $v = (new CustomStrategy)->vet($c, $this->bank(), $this->ctx(['size' => ['min_ticket_usd' => 100]]));

        $this->assertSame(Verdict::PASS, $v->verdict, $v->why);
    }

    public function test_thin_bids_deep_asks_rejects_a_long_entry_when_the_exit_check_fails(): void
    {
        $c = new Candidate($this->thinBidsDeepAsks(), 1.0, 'test');
        $v = (new CustomStrategy)->vet($c, $this->bank(), $this->ctx(['size' => ['min_ticket_usd' => 600]]));

        $this->assertSame('executable_liquidity', $v->failedCheck);
        $this->assertStringContainsString('same-size', $v->why, 'the rejection must name the exit check, not the entry');
        $this->assertStringContainsString('SELL', $v->why);
    }

    public function test_a_stale_quote_is_rejected_as_unavailable_not_silently_passed(): void
    {
        $stale = $this->stats([
            'bid_depth_usd' => 99_900_000.0, 'ask_depth_usd' => 99_900_000.0, 'quote_age_sec' => 999,
            'book_levels' => ['bids' => [[99.9, 1_000_000.0]], 'asks' => [[100.1, 1_000_000.0]], 'ref_price' => 100.0],
        ]);
        $c = new Candidate($stale, 1.0, 'test');
        $v = (new CustomStrategy)->vet($c, $this->bank(), $this->ctx(['size' => ['min_ticket_usd' => 100]]));

        $this->assertSame('executable_liquidity', $v->failedCheck);
        $this->assertStringContainsString('unavailable', $v->why);
    }

    public function test_a_missing_quote_age_is_also_unavailable(): void
    {
        $c = new Candidate($this->stats(['bid_depth_usd' => 1000, 'ask_depth_usd' => 1000]), 1.0, 'test'); // quote_age_sec left null
        $v = (new CustomStrategy)->vet($c, $this->bank(), $this->ctx(['size' => ['min_ticket_usd' => 10]]));

        $this->assertSame('executable_liquidity', $v->failedCheck);
        $this->assertStringContainsString('unavailable', $v->why);
    }

    public function test_missing_depth_with_a_fresh_quote_is_still_unavailable(): void
    {
        $c = new Candidate($this->stats(['quote_age_sec' => 0]), 1.0, 'test'); // no bid/ask depth at all
        $v = (new CustomStrategy)->vet($c, $this->bank(), $this->ctx(['size' => ['min_ticket_usd' => 10]]));

        $this->assertSame('executable_liquidity', $v->failedCheck);
        $this->assertStringContainsString('unavailable', $v->why);
    }

    public function test_historical_backtest_stats_skip_the_gate_explicitly_with_the_skip_counted(): void
    {
        // What ProductStatsBuilder::fromBars() actually produces: no book was ever fetched.
        $historical = $this->stats(['spread_bps' => 5.0]); // bid/ask depth, book_levels, quote_age_sec all absent -> null
        $c = new Candidate($historical, 1.0, 'test');
        $v = (new CustomStrategy)->vet($c, $this->bank(), $this->ctx(['size' => ['min_ticket_usd' => 100]], backtest: true));

        $this->assertSame(Verdict::PASS, $v->verdict, $v->why);
        $this->assertContains('executable_liquidity_skipped_backtest', $v->checksRun, 'the skip must be recorded, not silent');
        $this->assertNotContains('executable_liquidity', $v->checksRun);
    }

    public function test_the_depth_gate_can_be_disabled_for_live_paper_and_the_disable_is_counted(): void
    {
        $c = new Candidate($this->stats([]), 1.0, 'test'); // no depth data at all, live mode
        $v = (new CustomStrategy)->vet($c, $this->bank(), $this->ctx([
            'size' => ['min_ticket_usd' => 100, 'depth_gate' => false],
        ]));

        $this->assertSame(Verdict::PASS, $v->verdict, $v->why);
        $this->assertContains('executable_liquidity_disabled', $v->checksRun);
    }

    public function test_the_gate_uses_the_actual_notional_passed_in_not_a_kelly_proxy(): void
    {
        // Same candidate, same ctx — only the notional argument differs. A strategy with its own
        // sizing model can call vetExecutableLiquidity() directly (via its own vet() override) with
        // its real ticket instead of the inherited 6% Kelly proxy that vet() uses internally.
        $strategy = $this->strategy();
        $c = new Candidate($this->deepBidsThinAsks(), 1.0, 'test', side: 'short');
        $ctx = $this->ctx();

        $small = $strategy->gate($c, $ctx, 100.0);
        $this->assertNull($small['reject'], 'a $100 real ticket fits the thin $500.50 cover side');

        $large = $strategy->gate($c, $ctx, 600.0);
        $this->assertNotNull($large['reject'], 'a $600 real ticket does not fit the same thin cover side');
        $this->assertSame('executable_liquidity', $large['check']);
    }
}
