<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Indicators\Indicators;
use Tests\TestCase;

/**
 * Indicators::adx() against hand-computed values (Wilder's method, n=2, 4 bars — the minimum for
 * one seeded ADX value with no further smoothing, so the arithmetic is checkable by hand).
 */
class IndicatorsAdxTest extends TestCase
{
    /** @param array<int, array{0:float,1:float,2:float}> $hlc [high, low, close] per bar */
    private function bars(array $hlc): array
    {
        return array_map(fn ($b) => ['high' => $b[0], 'low' => $b[1], 'close' => $b[2]], $hlc);
    }

    public function test_adx_is_100_for_a_clean_monotonic_uptrend(): void
    {
        // Each bar: +DM=2, -DM=0, TR=3. Wilder-smoothed(n=2): smTr=[6,6], smPlus=[4,4], smMinus=[0,0].
        // +DI=66.667, -DI=0 throughout -> DX=[100,100] -> ADX = avg(100,100) = 100.
        $bars = $this->bars([
            [10, 8, 9],
            [12, 9, 11],
            [14, 11, 13],
            [16, 13, 15],
        ]);

        $this->assertEqualsWithDelta(100.0, Indicators::adx($bars, 2), 1e-6);
    }

    public function test_adx_is_zero_when_up_and_down_moves_are_always_tied(): void
    {
        // Each bar has upMove == downMove -> both +DM and -DM are 0 by the strict ">" rule, despite
        // real (and growing) true range. No net direction => DX=0 at every step => ADX=0.
        $bars = $this->bars([
            [10, 8, 9],
            [11, 7, 8],
            [12, 6, 11],
            [13, 5, 6],
        ]);

        $this->assertEqualsWithDelta(0.0, Indicators::adx($bars, 2), 1e-6);
    }

    public function test_adx_returns_null_with_fewer_than_2n_bars(): void
    {
        $bars = $this->bars([[10, 8, 9], [12, 9, 11], [14, 11, 13]]);   // 3 bars, need 2n=4 for n=2

        $this->assertNull(Indicators::adx($bars, 2));
    }
}
