<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Execution\MarginBook;
use App\Models\Position;
use Tests\TestCase;

class MarginBookTest extends TestCase
{
    private const INITIAL_RATE = 0.35;

    private const MAINTENANCE_RATE = 0.25;

    public function test_no_positions_gives_the_sentinel_buffer_and_full_buying_power(): void
    {
        $s = MarginBook::fromPositions(1000.0, [], fn () => 0.0, self::INITIAL_RATE, self::MAINTENANCE_RATE);

        $this->assertSame(1000.0, $s->availableMargin);
        $this->assertSame(0.0, $s->initialMargin);
        $this->assertEqualsWithDelta(1000.0 / 0.35, $s->futuresBuyingPower, 1e-9);
        $this->assertSame(1000.0, $s->liquidationBufferPct);
        $this->assertFalse(MarginBook::liquidated($s));
    }

    public function test_one_long_up_ten_percent_computes_equity_maintenance_and_buffer_by_hand(): void
    {
        // entry_usd 1000 (qty 10 @ 100), margin posted 350 (1000 * 35%), cash 650 left over.
        $p = new Position(['quantity' => 10.0, 'entry_price' => 100.0, 'entry_usd' => 1000.0, 'side' => 'long', 'meta' => ['margin_usd' => 350.0]]);

        // +10%: price 110. notional_now = 10*110 = 1100. unrealised = 10*110 - 1000 = 100.
        // equity = cash(650) + margin(350) + unrealised(100) = 1100.
        // initial_margin = 1100*0.35 = 385. buying_power = (1100-385)/0.35 = 2042.857142857143.
        // maintenance = 1100*0.25 = 275. buffer = (1100-275)/275*100 = 300%.
        $s = MarginBook::fromPositions(650.0, [$p], fn (Position $x) => 110.0, self::INITIAL_RATE, self::MAINTENANCE_RATE);

        $this->assertEqualsWithDelta(385.0, $s->initialMargin, 1e-9);
        $this->assertEqualsWithDelta(2042.857142857143, $s->futuresBuyingPower, 1e-6);
        $this->assertEqualsWithDelta(300.0, $s->liquidationBufferPct, 1e-9);
        $this->assertFalse(MarginBook::liquidated($s));
    }

    public function test_forty_percent_drop_at_three_times_leverage_liquidates(): void
    {
        // entry_usd 3000 (qty 30 @ 100), margin posted 1000 -- 3x leverage against the margin actually posted
        // (equity at entry = cash(0) + margin(1000) = 1000; notional(3000) / equity(1000) = 3).
        $p = new Position(['quantity' => 30.0, 'entry_price' => 100.0, 'entry_usd' => 3000.0, 'side' => 'long', 'meta' => ['margin_usd' => 1000.0]]);

        // -40%: price 60. notional_now = 30*60 = 1800. unrealised = 1800 - 3000 = -1200.
        // equity = cash(0) + margin(1000) + unrealised(-1200) = -200. maintenance = 1800*0.25 = 450.
        // buffer = (-200-450)/450*100 = -144.44%.
        $s = MarginBook::fromPositions(0.0, [$p], fn (Position $x) => 60.0, self::INITIAL_RATE, self::MAINTENANCE_RATE);

        $this->assertEqualsWithDelta(-144.44444444444, $s->liquidationBufferPct, 1e-6);
        $this->assertTrue(MarginBook::liquidated($s));
    }
}
