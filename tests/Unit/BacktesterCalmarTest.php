<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Backtester;
use Tests\TestCase;

class BacktesterCalmarTest extends TestCase
{
    private function calmar(float $startEq, float $endEq, float $maxDd): ?float
    {
        return Backtester::stats([], 0, 86400, $startEq, $endEq, $maxDd, [])['calmar'];
    }

    public function test_zero_drawdown_uses_the_half_percent_floor_instead_of_a_sentinel(): void
    {
        $this->assertSame(2.0, $this->calmar(100.0, 101.0, 0.0));
    }

    public function test_real_drawdown_divides_return_by_it(): void
    {
        $this->assertSame(5.0, $this->calmar(100.0, 110.0, 2.0));
    }

    public function test_a_large_ratio_is_clamped_to_the_cap(): void
    {
        $this->assertSame(50.0, $this->calmar(100.0, 120.0, 0.1));
    }

    public function test_calmar_is_null_when_return_is_null(): void
    {
        $this->assertNull($this->calmar(0.0, 100.0, 1.0));
    }
}
