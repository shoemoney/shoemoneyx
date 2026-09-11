<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Indicators\Smx;
use Tests\TestCase;

class SmxTest extends TestCase
{
    /** A sine-wave market: WT must oscillate, cross, and print buys near troughs and sells near peaks. */
    private function bars(int $n = 400, float $period = 40): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $c = 100 + 10 * sin($i / $period * 2 * M_PI) + 0.3 * sin($i * 1.7);
            $o = 100 + 10 * sin(($i - 1) / $period * 2 * M_PI) + 0.3 * sin(($i - 1) * 1.7);
            $out[] = ['start' => $i * 3600, 'open' => $o, 'high' => max($o, $c) + 0.5, 'low' => min($o, $c) - 0.5, 'close' => $c, 'volume' => 1.0];
        }

        return $out;
    }

    public function test_series_are_aligned_and_in_range(): void
    {
        $bars = $this->bars();
        $c = Smx::compute($bars);
        foreach (['wt1', 'wt2', 'rsi_mfi', 'rsi', 'stoch_k', 'stoch_d', 'stc'] as $k) {
            $this->assertCount(count($bars), $c[$k], $k);
        }
        $rsi = array_filter($c['rsi'], fn ($v) => $v !== null);
        $this->assertGreaterThanOrEqual(0, min($rsi));
        $this->assertLessThanOrEqual(100, max($rsi));
        $wt2 = array_filter($c['wt2'], fn ($v) => $v !== null);
        $this->assertLessThan(-53, min($wt2), 'a clean sine wave must reach the oversold band');
        $this->assertGreaterThan(53, max($wt2));
    }

    public function test_crosses_alternate_and_buys_land_in_oversold(): void
    {
        $c = Smx::compute($this->bars());
        $crosses = array_keys(array_filter($c['wt_cross']));
        $this->assertGreaterThan(10, count($crosses));
        $buys = array_keys(array_filter($c['signals']['buy']));
        $sells = array_keys(array_filter($c['signals']['sell']));
        $this->assertNotEmpty($buys);
        $this->assertNotEmpty($sells);
        foreach ($buys as $i) {
            $this->assertTrue($c['wt_cross'][$i] && $c['wt_cross_up'][$i] && $c['wt2'][$i] <= -53, "buy at bar {$i} must be a cross up in oversold");
        }
        foreach ($sells as $i) {
            $this->assertTrue($c['wt_cross'][$i] && $c['wt_cross_down'][$i] && $c['wt2'][$i] >= 53, "sell at bar {$i} must be a cross down in overbought");
        }
        // buys and sells alternate on a sine wave
        $all = array_fill_keys($buys, 'b') + array_fill_keys($sells, 's');
        ksort($all);
        $prev = null;
        foreach ($all as $i => $kind) {
            $this->assertNotSame($prev, $kind, "two consecutive {$kind} signals at bar {$i}");
            $prev = $kind;
        }
    }

    public function test_fractal_divergence_finder(): void
    {
        // oscillator makes a lower high while price makes a higher high -> bearish divergence
        $src = array_fill(0, 30, 0.0);
        $high = array_fill(0, 30, 100.0);
        $low = array_fill(0, 30, 90.0);
        $src[10] = 60;
        $src[9] = 50;
        $src[11] = 50;
        $high[10] = 110;   // first top (60, price 110)
        $src[20] = 50;
        $src[19] = 40;
        $src[21] = 40;
        $high[20] = 115;  // second top lower osc (50), higher price
        $d = Smx::divergences($src, $high, $low, 45, -65, true);
        $this->assertTrue($d['fractal_top'][12]);
        $this->assertTrue($d['fractal_top'][22]);
        $this->assertTrue($d['bear'][22], 'lower oscillator high on a higher price high is a bearish divergence');
        $this->assertFalse($d['bear'][12]);
    }

    public function test_ema_matches_pine_seed(): void
    {
        $e = Smx::ema([1, 2, 3, 4, 5, 6], 3);
        $this->assertNull($e[1]);
        $this->assertEqualsWithDelta(2.0, $e[2], 1e-9);         // SMA seed
        $this->assertEqualsWithDelta(3.0, $e[3], 1e-9);         // 4*0.5 + 2*0.5
    }
}
