<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Indicators\Indicators;
use Tests\TestCase;

/** Indicators::bb/vwap/obv against small hand-computed series. */
class IndicatorsBbVwapObvTest extends TestCase
{
    public function test_bollinger_bands_hand_computed(): void
    {
        // closes 1..5: mean 3, population variance ((4+1+0+1+4)/5)=2, sd=sqrt(2).
        $bands = Indicators::bb([1.0, 2.0, 3.0, 4.0, 5.0], 5, 2.0);

        $sd = sqrt(2.0);
        $this->assertEqualsWithDelta(3.0, $bands['mid'], 1e-9);
        $this->assertEqualsWithDelta(3.0 + 2 * $sd, $bands['upper'], 1e-9);
        $this->assertEqualsWithDelta(3.0 - 2 * $sd, $bands['lower'], 1e-9);
        // pos = (last - lower) / (upper - lower)
        $this->assertEqualsWithDelta((5.0 - (3.0 - 2 * $sd)) / (4 * $sd), $bands['pos'], 1e-9);
    }

    public function test_bollinger_bands_null_when_short(): void
    {
        $bands = Indicators::bb([1.0, 2.0], 5, 2.0);

        $this->assertNull($bands['mid']);
        $this->assertNull($bands['upper']);
        $this->assertNull($bands['lower']);
        $this->assertNull($bands['pos']);
    }

    private function bar(int $start, float $h, float $l, float $c, float $v): array
    {
        return ['start' => $start, 'open' => $c, 'high' => $h, 'low' => $l, 'close' => $c, 'volume' => $v];
    }

    public function test_vwap_hand_computed_within_one_session(): void
    {
        $day1 = strtotime('2026-01-02T00:00:00Z');
        $bars = [
            $this->bar($day1, 10, 8, 9, 100),
            $this->bar($day1 + 3600, 11, 9, 10, 200),
            $this->bar($day1 + 7200, 12, 10, 11, 300),
        ];
        // typical prices 9, 10, 11; pv = 900 + 2000 + 3300 = 6200; vol = 600 -> 10.3333...
        $this->assertEqualsWithDelta(6200 / 600, Indicators::vwap($bars), 1e-9);
    }

    public function test_vwap_resets_at_the_utc_day_boundary(): void
    {
        $day0 = strtotime('2026-01-01T23:00:00Z');
        $day1 = strtotime('2026-01-02T00:00:00Z');
        $bars = [
            // Previous day, wildly different price/volume -- must be excluded from the session VWAP.
            $this->bar($day0, 1000, 1000, 1000, 999999),
            $this->bar($day1, 10, 10, 10, 50),
            $this->bar($day1 + 3600, 20, 20, 20, 50),
        ];

        $this->assertEqualsWithDelta(15.0, Indicators::vwap($bars), 1e-9);
    }

    public function test_vwap_null_on_empty_bars(): void
    {
        $this->assertNull(Indicators::vwap([]));
    }

    public function test_obv_hand_computed(): void
    {
        $closes = [10.0, 11.0, 10.0, 12.0, 12.0, 11.0];
        $volumes = [100.0, 150.0, 120.0, 200.0, 90.0, 80.0];
        $bars = [];
        foreach ($closes as $i => $c) {
            $bars[] = ['start' => $i * 3600, 'open' => $c, 'high' => $c, 'low' => $c, 'close' => $c, 'volume' => $volumes[$i]];
        }

        // +150 (11>10), -120 (10<11), +200 (12>10), +0 (12==12), -80 (11<12) = 150
        $this->assertEqualsWithDelta(150.0, Indicators::obv($bars), 1e-9);
    }

    public function test_obv_null_with_fewer_than_two_bars(): void
    {
        $this->assertNull(Indicators::obv([$this->bar(0, 1, 1, 1, 1)]));
        $this->assertNull(Indicators::obv([]));
    }
}
