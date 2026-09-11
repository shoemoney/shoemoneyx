<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Data\Bank;
use App\Desk\Data\ProductStats;
use App\Desk\Data\Verdict;
use App\Desk\DeskContext;
use App\Desk\Strategies\CustomStrategy;
use App\Models\Position;
use App\Support\Fees;
use App\Support\Kelly;
use Carbon\Carbon;
use Tests\TestCase;

class StrategyTest extends TestCase
{
    private function stats(array $o = []): ProductStats
    {
        return ProductStats::fromArray($o + [
            'product_id' => 'TEST-USD', 'price' => 10.0, 'best_bid' => 9.99, 'best_ask' => 10.01, 'age_hours' => 500,
            'volume_m5_usd' => 5000, 'volume_h1_usd' => 100000, 'volume_h6_usd' => 400000, 'volume_h24_usd' => 1200000, 'volume_prev_h24_usd' => 800000,
            'price_change_m5_pct' => 0.1, 'price_change_h1_pct' => 1.0, 'price_change_h6_pct' => 2.0, 'price_change_h24_pct' => 4.0,
            'buys_h1' => 70, 'sells_h1' => 30, 'buys_m5' => 6, 'sells_m5' => 4, 'buy_volume_h1_usd' => 70000, 'sell_volume_h1_usd' => 30000,
            'spread_bps' => 5, 'book_depth_usd' => 50000, 'bid_depth_usd' => 50000, 'ask_depth_usd' => 50000,
            'quote_age_sec' => 1, 'tape_sample_size' => 100,
            'book_levels' => ['bids' => [[9.99, 100000], [9.98, 100000]], 'asks' => [[10.01, 100000], [10.02, 100000]], 'ref_price' => 10.0],
            'candles_h1_count' => 48,
        ]);
    }

    private function ctx(array $overrides = [], array $open = []): DeskContext
    {
        $s = new CustomStrategy;
        $params = array_replace_recursive([
            'fees' => ['taker_rate' => 0.006, 'floor_usd' => 0, 'max_effective_fee_pct' => 0.02],
            'paper' => ['slippage_bps' => 15],
            'bank' => ['locked_pct' => 0.2],
        ], $s->defaults(), $overrides);

        return new DeskContext($params, 'paper', false, $open, new \DateTimeImmutable('2026-09-03 12:00:00'));
    }

    public function test_kelly_is_capped(): void
    {
        $this->assertEqualsWithDelta(0.06, Kelly::fraction(0.9, 2.0, 1.0, 0.06), 1e-9);
        $this->assertSame(0.0, Kelly::fraction(0.3, 1.0, 0.5, 0.06));
        $this->assertEqualsWithDelta(0.125, Kelly::fraction(0.55, 1.5, 0.5, 1.0), 0.001);
    }

    public function test_fee_floor_math_matches_the_post(): void
    {
        // "$20 entry against a $0.95 floor is 4.75%"
        $this->assertEqualsWithDelta(0.0475, Fees::effectiveRate(20, 0.0045, 0.95), 1e-6);
    }

    public function test_scan_ranks_surging_participation_first_and_drops_sellers(): void
    {
        $s = new CustomStrategy;
        $hot = $this->stats(['product_id' => 'HOT-USD', 'volume_h1_usd' => 250000]);
        $flat = $this->stats(['product_id' => 'FLAT-USD', 'volume_h1_usd' => 50000, 'buys_h1' => 50, 'sells_h1' => 50]);
        $dump = $this->stats(['product_id' => 'DUMP-USD', 'buys_h1' => 20, 'sells_h1' => 80, 'buys_m5' => 1, 'sells_m5' => 9]);
        $thin = $this->stats(['product_id' => 'THIN-USD', 'volume_h24_usd' => 1000]);
        $young = $this->stats(['product_id' => 'NEW-USD', 'age_hours' => 3]);

        $out = $s->scan([$flat, $dump, $hot, $thin, $young], $this->ctx());
        $ids = array_map(fn ($c) => $c->productId(), $out);

        $this->assertSame('HOT-USD', $ids[0]);
        $this->assertNotContains('DUMP-USD', $ids);
        $this->assertNotContains('THIN-USD', $ids);
        $this->assertNotContains('NEW-USD', $ids);
    }

    public function test_vet_rejects_in_order_and_passes_clean_candidate(): void
    {
        $s = new CustomStrategy;
        $bank = new Bank(1000, 0, 0.2, 0, 0);
        $ctx = $this->ctx();

        [$clean] = $s->scan([$this->stats(['volume_h1_usd' => 200000])], $ctx);
        $this->assertSame(Verdict::PASS, $s->vet($clean, $bank, $ctx)->verdict);

        [$wide] = $s->scan([$this->stats(['volume_h1_usd' => 200000, 'spread_bps' => 80])], $ctx);
        $this->assertSame('spread_and_book', $s->vet($wide, $bank, $ctx)->failedCheck);

        [$priced] = $s->scan([$this->stats(['volume_h1_usd' => 200000, 'price_change_h1_pct' => 20])], $ctx);
        $this->assertSame('already_priced', $s->vet($priced, $bank, $ctx)->failedCheck);

        [$quiet] = $s->scan([$this->stats(['volume_h1_usd' => 30000, 'buys_h1' => 90, 'sells_h1' => 10])], $ctx);
        $this->assertSame('attention', $s->vet($quiet, $bank, $ctx)->failedCheck);

        [$full] = $s->scan([$this->stats(['volume_h1_usd' => 200000])], $ctx);
        $seven = array_map(fn ($i) => new Position(['product_id' => "P{$i}-USD", 'status' => 'open']), range(1, 7));
        $this->assertSame('capacity', $s->vet($full, $bank, $this->ctx([], $seven))->failedCheck);
    }

    public function test_size_respects_cap_free_cash_and_minimum(): void
    {
        $s = new CustomStrategy;
        $ctx = $this->ctx();
        [$c] = $s->scan([$this->stats(['volume_h1_usd' => 200000])], $ctx);
        $v = $s->vet($c, new Bank(1000, 0, 0.2, 0, 0), $ctx);

        $d = $s->size($v, new Bank(1000, 0, 0.2, 0, 0), $ctx);
        $this->assertEqualsWithDelta(60.0, $d->dollars, 0.01, '6% of the book is the ceiling');
        $this->assertTrue($d->ceilingApplied);

        $d = $s->size($v, new Bank(5, 0, 0.2, 0, 0), $ctx);
        $this->assertSame(0.0, $d->dollars, 'free cash under the minimum ticket returns 0');

        $partial = new Verdict($c, Verdict::PASS_PARTIAL, null, [], ['context'], null, 'partial');
        $d = $s->size($partial, new Bank(1000, 0, 0.2, 0, 0), $ctx);
        $this->assertEqualsWithDelta(30.0, $d->dollars, 0.01, 'PASS_PARTIAL cuts the ticket');
    }

    public function test_risk_closes_on_the_volume_rule_and_holds_otherwise(): void
    {
        $s = new CustomStrategy;
        $ctx = $this->ctx();
        $p = new Position(['product_id' => 'TEST-USD', 'quantity' => 10, 'entry_price' => 10, 'entry_usd' => 100, 'peak_price' => 10, 'opened_at' => Carbon::parse('2026-09-03 10:00:00')]);

        // The post's own example: h24 4717.70, h6 167.75 -> ratio 0.142 -> CLOSE
        $dry = $this->stats(['volume_h24_usd' => 4717.70, 'volume_h6_usd' => 167.75]);
        $d = $s->risk($p, $dry, $ctx);
        $this->assertTrue($d->shouldClose());
        $this->assertSame('volume_dry', $d->ruleFired);
        $this->assertEqualsWithDelta(0.142, $d->ratio, 0.001);

        $this->assertFalse($s->risk($p, $this->stats(), $ctx)->shouldClose());

        $crash = $this->stats(['price' => 8.5]);
        $this->assertSame('hard_stop', $s->risk($p, $crash, $ctx)->ruleFired);

        $p->peak_price = 17.0; // +70% peak, now +30% -> gave back 40 points > 25 giveback
        $this->assertSame('trail_giveback', $s->risk($p, $this->stats(['price' => 13.0]), $ctx)->ruleFired);

        $old = new Position(['product_id' => 'TEST-USD', 'quantity' => 10, 'entry_price' => 10, 'entry_usd' => 100, 'peak_price' => 10, 'opened_at' => Carbon::parse('2026-08-30 10:00:00')]);
        $this->assertSame('max_hold', $s->risk($old, $this->stats(), $ctx)->ruleFired);
    }
}
