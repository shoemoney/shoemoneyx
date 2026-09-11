<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Backtester;
use App\Models\Candle;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fixtures\BarWarmupProbeStrategy;
use Tests\TestCase;

/**
 * Backtester preloaded 1H bars from $from - 50h, and every '1H' bars() request from a strategy
 * routes to that same preloaded array (never the 600-bar warmup loader further down, which only
 * serves timeframes other than the step tf and '1H'). A trend gate needing 200 closed 1H bars
 * (MeanReversionStrategy's trendOk(), need = len) was therefore short by 150+
 * bars at the very first step of every run, returned null ("insufficient history"), and the caller
 * fails that closed -- silently zeroing every entry gated on it (2026-09-05).
 *
 * Preloading 600h instead of 50h fixes this: assert a 200-bar gate sees at least 200 closed bars
 * at $from, the very first step of the run.
 */
class BacktestBarWarmupTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_200_bar_trend_gate_has_enough_history_at_the_first_step(): void
    {
        config(['cache.default' => 'array']);
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);

        $from = Carbon::parse('2024-03-01 00:00:00', 'UTC');
        $to = $from->copy()->addHours(2);

        // 250 closed hourly bars strictly before $from -- comfortably more than the 202
        // (need + 2) a 200-bar trend gate fetches via trendOk()'s own shape.
        for ($i = 250; $i >= 1; $i--) {
            Candle::create([
                'product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from->copy()->subHours($i),
                'open' => 80000, 'high' => 80000, 'low' => 80000, 'close' => 80000, 'volume' => 1,
            ]);
        }

        config(['desk.strategies.bar_warmup_probe_test' => BarWarmupProbeStrategy::class]);
        $probe = new BarWarmupProbeStrategy('BTC-USD', 200);
        $this->app->instance(BarWarmupProbeStrategy::class, $probe);

        app(Backtester::class)->run('bar_warmup_probe_test', ['BTC-USD'], $from, $to, 10000.0);

        $this->assertNotNull($probe->barsAtFirstStep, 'the probe never scanned -- the backtest loop did not run a first step');
        $this->assertGreaterThanOrEqual(200, $probe->barsAtFirstStep);
    }
}
