<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Data\ProductStats;
use App\Desk\DeskContext;
use App\Desk\Strategies\JsonPluginStrategy;
use App\Models\Position;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Round-6 review, BLOCKER 3: reentryArmDecision() divided `$dollars / $price` with no price
 * guard. ProductStatsBuilder::fromBars() returns price 0.0 for a product with no closed 1H bars
 * yet, and Desk::statsWithRetries() passes that straight through as a real (non-null) stats row —
 * every guard above the division survives a literal 0, so this threw DivisionByZeroError, which
 * (pre round-6 blocker 1) starved every position after it in the sweep.
 */
class JsonPluginStrategyReentryPriceGuardTest extends TestCase
{
    private function stats(float $price): ProductStats
    {
        return ProductStats::fromArray([
            'product_id' => 'BTC-USD', 'price' => $price,
            'volume_h24_usd' => 600000, 'volume_h1_usd' => 100000, 'volume_h6_usd' => 400000,
            'price_change_h24_pct' => 3, 'spread_bps' => 5, 'candles_h1_count' => 30,
        ]);
    }

    private function ctx(): DeskContext
    {
        return new DeskContext([], 'paper', false, [], Carbon::parse('2024-01-01 00:00:00')->toDateTimeImmutable());
    }

    private function position(): Position
    {
        return new Position([
            'mode' => 'paper', 'strategy' => 'json', 'product_id' => 'BTC-USD', 'status' => 'open', 'side' => 'long',
            'quantity' => 900.0, 'entry_price' => 100.0, 'entry_usd' => 90000.0, 'fees_usd' => 0.0,
            'peak_price' => 110.0, 'last_price' => 105.0, 'adds_count' => 0, 'trims_count' => 1,
            'opened_at' => Carbon::parse('2024-01-01 00:00:00'), 'meta' => [
                'v2' => ['ladder' => ['avg' => 100.0, 'original_qty' => 1000.0, 'fired' => [0], 'sold' => [0 => ['qty' => 100.0, 'price' => 110.0]]]],
            ],
        ]);
    }

    private function invoke(Position $p, ProductStats $s, DeskContext $ctx): mixed
    {
        $def = [
            'take_profit' => ['ladder' => [['at_pct' => 10.0, 'sell_pct_of_original' => 10.0]]],
        ];
        $reentry = [
            'after_rungs' => 1,
            'retrace' => ['of' => 'rung_spacing', 'min' => 0.1, 'stay_above_avg' => false],
            'min_spacing_x_fees' => 0,
            'size' => ['mode' => 'usd', 'value' => 100.0],
        ];
        $ladder = $p->meta['v2']['ladder'];

        $strategy = new JsonPluginStrategy;
        $r = new \ReflectionMethod(JsonPluginStrategy::class, 'reentryArmDecision');
        $r->setAccessible(true);

        return $r->invoke($strategy, $p, $s, $def, $reentry, $def['take_profit'], $ladder, $ctx, '1h');
    }

    #[Test]
    public function reentry_arm_decision_returns_null_instead_of_dividing_by_a_zero_price(): void
    {
        $decision = $this->invoke($this->position(), $this->stats(0.0), $this->ctx());

        $this->assertNull($decision, 'a price-0 stats row must be refused, not divided into');
    }

    #[Test]
    public function reentry_arm_decision_still_arms_normally_at_a_real_price(): void
    {
        // Sanity control: the same setup with a real price must not be refused by the new guard.
        $decision = $this->invoke($this->position(), $this->stats(101.0), $this->ctx());

        $this->assertNotNull($decision, 'a healthy price must still be able to arm a re-entry, or this test proves nothing');
        $this->assertTrue($decision->shouldAdd());
    }
}
