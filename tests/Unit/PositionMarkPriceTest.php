<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Position;
use Tests\TestCase;

class PositionMarkPriceTest extends TestCase
{
    public function test_long_peak_rises_on_higher_price_and_holds_on_lower(): void
    {
        $p = new Position(['side' => 'long', 'entry_price' => 100, 'peak_price' => 100, 'last_price' => 100]);

        $p->markPrice(105);
        $this->assertSame(105.0, (float) $p->peak_price);
        $this->assertSame(105.0, (float) $p->last_price);

        $p->markPrice(102);
        $this->assertSame(105.0, (float) $p->peak_price, 'peak holds when price pulls back');
        $this->assertSame(102.0, (float) $p->last_price);
    }

    public function test_short_peak_falls_on_lower_price_and_holds_on_higher(): void
    {
        $p = new Position(['side' => 'short', 'entry_price' => 100, 'peak_price' => 100, 'last_price' => 100]);

        $p->markPrice(95);
        $this->assertSame(95.0, (float) $p->peak_price);

        $p->markPrice(98);
        $this->assertSame(95.0, (float) $p->peak_price, 'peak holds when price bounces against a short');
        $this->assertSame(98.0, (float) $p->last_price);
    }

    public function test_null_peak_seeds_from_the_first_mark(): void
    {
        $long = new Position(['side' => 'long', 'entry_price' => 100, 'peak_price' => null]);
        $long->markPrice(97);
        $this->assertSame(97.0, (float) $long->peak_price, 'first mark seeds the peak even though it is below entry');

        $short = new Position(['side' => 'short', 'entry_price' => 100, 'peak_price' => 0]);
        $short->markPrice(103);
        $this->assertSame(103.0, (float) $short->peak_price, 'a zero peak is unset, not a real extreme to beat');
    }
}
