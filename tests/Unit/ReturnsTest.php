<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Returns;
use App\Support\Sharpe;
use Tests\TestCase;

class ReturnsTest extends TestCase
{
    public function test_from_curve_takes_simple_returns_between_consecutive_points(): void
    {
        $curve = [[0, 100], [14400, 110], [28800, 99]];

        [$r1, $r2] = Returns::fromCurve($curve);
        $this->assertEqualsWithDelta(0.1, $r1, 1e-9);
        $this->assertEqualsWithDelta(-0.1, $r2, 1e-9);
        $this->assertSame([], Returns::fromCurve([[0, 100]]), 'a single point has no return');
    }

    public function test_sharpe_is_mean_over_population_stdev(): void
    {
        $this->assertEqualsWithDelta(2.4494897, Returns::sharpe([1, 2, 3]), 1e-6);
        $this->assertNull(Returns::sharpe([1, 2]), 'fewer than 3 returns');
        $this->assertNull(Returns::sharpe([1, 1, 1]), 'zero stdev');
    }

    public function test_skew_is_zero_on_a_symmetric_series(): void
    {
        $this->assertEqualsWithDelta(0.0, Returns::skew([-2, -1, 0, 1, 2]), 1e-9);
        $this->assertSame(0.0, Returns::skew([1, 2]), 'undefined defaults to 0.0');
    }

    public function test_kurt_defaults_to_3_when_undefined(): void
    {
        $this->assertEqualsWithDelta(1.7, Returns::kurt([-2, -1, 0, 1, 2]), 1e-9);
        $this->assertSame(3.0, Returns::kurt([1, 2]), 'fewer than 3 points');
        $this->assertSame(3.0, Returns::kurt([1, 1, 1]), 'zero stdev');
    }

    public function test_phi_and_z_spot_checks(): void
    {
        $this->assertEqualsWithDelta(0.975, Sharpe::phi(1.96), 5e-5);
        $this->assertEqualsWithDelta(1.95996, Sharpe::z(0.975), 5e-5);
        $this->assertEqualsWithDelta(0.5, Sharpe::phi(0.0), 1e-9);
        $this->assertEqualsWithDelta(0.0, Sharpe::z(0.5), 1e-9);
    }

    public function test_deflated_sharpe_hand_computed_case(): void
    {
        // sr 0.25, a single trial (no multiple-testing deflation, sr0 = 0), obs 100, normal skew/kurt.
        $solo = Sharpe::deflated(0.25, 1, 0.0, 100, 0.0, 3.0);
        $this->assertEqualsWithDelta(Sharpe::phi(0.25 * sqrt(99) / sqrt(1.03125)), $solo, 1e-9);
        $this->assertEqualsWithDelta(0.9928, $solo, 1e-3);

        // more trials with non-zero variance among them raises sr0 and lowers the deflated score.
        $manyTrials = Sharpe::deflated(0.25, 97, 0.01, 100, 0.0, 3.0);
        $this->assertLessThan($solo, $manyTrials, 'more trials deflate the Sharpe ratio');

        $this->assertNull(Sharpe::deflated(0.25, 1, 0.0, 2), 'fewer than 3 observations');
    }
}
