<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Execution\Quantize;
use PHPUnit\Framework\TestCase;

class QuantizeTest extends TestCase
{
    public function test_a_decimal_that_binary_floats_undershoot_survives_the_floor(): void
    {
        $this->assertSame(28.0, floor(0.29 * 100), 'the naive floor loses a whole increment');
        $this->assertSame(0.29, Quantize::floorTo(0.29, 2));
    }

    public function test_a_value_just_below_a_step_never_snaps_above_the_input(): void
    {
        $this->assertSame(0.0, Quantize::floorTo(0.9999999999999999, 0));
        $this->assertSame(0.28, Quantize::floorTo(0.28999999999999998 - 1e-15, 2));
        $this->assertSame(0.29, Quantize::floorTo(0.29, 2));
    }

    public function test_it_still_floors_real_remainders(): void
    {
        $this->assertSame(0.12, Quantize::floorTo(0.1234, 2));
        $this->assertSame(0.0, Quantize::floorTo(0.004, 2));
        $this->assertSame(1.0, Quantize::floorTo(1.999999, 0));
        $this->assertSame(0.0, Quantize::floorTo(-1.0, 2));
    }

    public function test_sweep_every_decimal_at_every_precision_round_trips_exactly(): void
    {
        $naiveWrong = 0;
        foreach ([0, 1, 2, 3, 4, 6, 8] as $precision) {
            $factor = 10 ** $precision;
            for ($units = 1; $units <= 5000; $units++) {
                $value = (float) number_format($units / $factor, $precision, '.', '');
                $this->assertSame($value, Quantize::floorTo($value, $precision), "precision {$precision}, units {$units}");
                if (floor($value * $factor) / $factor !== $value) {
                    $naiveWrong++;
                }
            }
        }
        $this->assertGreaterThan(0, $naiveWrong, 'the sweep must include values the naive floor gets wrong');
    }

    public function test_sweep_a_value_just_above_a_step_floors_to_that_step_and_never_exceeds_the_input(): void
    {
        foreach ([2, 8] as $precision) {
            $factor = 10 ** $precision;
            for ($units = 1; $units <= 3000; $units++) {
                $input = $units / $factor + 0.4 / $factor;
                $floored = Quantize::floorTo($input, $precision);
                $this->assertSame((float) number_format($units / $factor, $precision, '.', ''), $floored);
                $this->assertLessThanOrEqual($input, $floored);
            }
        }
    }
}
