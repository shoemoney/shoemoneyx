<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Backtester;
use App\Models\Candle;
use App\Models\Product;
use App\Models\StrategyPlugin;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shipped SMX π Take Profit v2 example (resources/strategies/examples/smx-pi-take-profit-v2.json)
 * run end to end through Backtester — real SCAN/VET/SIZE gating, real ProductStatsBuilder-derived
 * stats and a real IndicatorCache-backed ind.rsi(14) — not a hand-fed ProductStats fixture (see
 * JsonPluginStrategyV2EngineTest for that, canned-tape style, which pins the exact ladder/reentry
 * fractions this test exercises through the real pipeline).
 *
 * The candle tape: 30 warmup bars (a mild ±0.3% oscillation, RSI-neutral), a +2.5% high-volume bar
 * SCAN reads as its candidate (momentum + liquidity signals hold, not_overbought holds), then a
 * small natural pullback bar whose OPEN is the fill price — that pullback is what keeps RSI(14)
 * under the not_overbought gate a few hours later, when the ladder has climbed and the dip needs
 * to re-check entry.when for the reentry to arm. Every following close is that fill price times a
 * ratio, so the WHOLE post-entry walk (four rungs, a retrace that arms and cashes out a reentry,
 * the ladder re-arming from the new average, a new peak, the runner's TTP close) is driven by real
 * RISK() calls against real bar-derived stats, exactly the sequence JsonPluginStrategyV2EngineTest
 * verifies directly. Fees, slippage and funding are zeroed so the ending equity is exactly the sum
 * of what SIZE put in and every trim/add/close moved — a closed-form number, not a tuned constant.
 */
class JsonRunnerV2LadderReentryBacktestTest extends TestCase
{
    use RefreshDatabase;

    private function setUpProduct(): void
    {
        config(['cache.default' => 'array']);
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);
    }

    private function definePlugin(): array
    {
        $def = json_decode(file_get_contents(base_path('resources/strategies/examples/smx-pi-take-profit-v2.json')), true);
        StrategyPlugin::create(['key' => $def['key'], 'name' => $def['meta']['name'], 'definition' => $def]);

        return $def;
    }

    /** @return array{0: Carbon, 1: Carbon, 2: float, 3: float} [from, to, jump close E, fill price F] */
    private function warmupAndEntry(Carbon $from): array
    {
        $price = 100.0;
        $ts = $from->copy();
        for ($i = 0; $i < 30; $i++) {
            $ts->addHour();
            $open = $price;
            $price *= 1 + ($i % 2 === 0 ? 0.003 : -0.003);
            Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $ts->copy(), 'open' => $open, 'high' => max($open, $price), 'low' => min($open, $price), 'close' => $price, 'volume' => 230]);
        }
        // The jump SCAN reads: +2.5% on a volume spike (liquid, momentum, not_overbought all hold).
        $ts->addHour();
        $open = $price;
        $jump = $price * 1.025;
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $ts->copy(), 'open' => $open, 'high' => max($open, $jump), 'low' => min($open, $jump), 'close' => $jump, 'volume' => 900]);

        // Entry fills at the NEXT bar's open — a small natural pullback off the jump.
        $fill = $jump * 0.995;
        $ts->addHour();
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $ts->copy(), 'open' => $fill, 'high' => $fill, 'low' => $fill, 'close' => $fill, 'volume' => 230]);

        return [$ts, $jump, $fill];
    }

    /** @param array<int, float> $ratios closes as a multiple of the fill price, one candle per ratio */
    private function walk(Carbon $ts, float $fill, array $ratios): Carbon
    {
        $prev = $fill;
        foreach ($ratios as $r) {
            $close = $fill * $r;
            $ts->addHour();
            Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $ts->copy(), 'open' => $prev, 'high' => max($prev, $close), 'low' => min($prev, $close), 'close' => $close, 'volume' => 230]);
            $prev = $close;
        }

        return $ts;
    }

    /** @return array<string, float> */
    private function overrides(string $key): array
    {
        return [
            'json.plugin_key' => $key,
            'fees.taker_rate' => 0.0, 'fees.maker_rate' => 0.0, 'fees.funding_hourly_pct' => 0.0,
            'fees.per_contract_usd' => 0.0, 'fees.contract_usd' => 0.0,
            'paper.slippage_bps' => 0.0,
            // The base pipeline's own liquidity gate (unrelated to v2's own signals) would reject a
            // $100k ticket against this tape's ~$600-650k 24h volume at its default 0.5% cap.
            'vet.max_pct_of_volume_24h' => 100.0,
        ];
    }

    public function test_the_ladder_reentry_rearm_and_runner_ttp_survive_a_real_scan_vet_size_run(): void
    {
        $this->setUpProduct();
        $def = $this->definePlugin();
        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        [$ts, , $fill] = $this->warmupAndEntry($from);

        // Rung 0 (+1.6%, a hair past its 1.5% target), then a real multi-bar retrace back toward
        // avg that arms one reentry buy — the widened 1.5%-spaced ladder pushes RSI(14) well past
        // the not_overbought(70) gate reentry.when also checks after only ONE rung, so the retrace
        // is spread over several bars to let RSI cool before arming, rather than firing all four
        // original rungs first (after_rungs defaults to 1 — this is still the shipped example's own
        // gate, not a relaxed one). The reentry cashes out once green, the ladder re-arms from the
        // new average and climbs all four rungs again, then a peak and a 1%+ giveback fires the
        // runner.
        $ts = $this->walk($ts, $fill, [1.016, 1.010, 1.006, 1.003, 1.0005, 1.007, 1.017, 1.032, 1.047, 1.062, 1.10, 1.08]);
        $to = $ts->copy()->addHour();

        $bt = app(Backtester::class)->run('json', ['BTC-USD'], $from, $to, 2_000_000.0, $this->overrides($def['key']));

        $this->assertSame('done', $bt->status);
        $trade = $bt->trades[0];
        $this->assertSame('take_profit.runner.ttp', $trade['rule']);
        $this->assertSame(1, $trade['adds'], 'exactly one reentry buy');
        $this->assertSame(6, $trade['trims'], 'rung 0 + cash_out + 4 re-armed rungs');

        // Closed form: SIZE puts in 5% of the $2,000,000 starting equity ($100,000, no fees/slippage)
        // at the fill price; every rung/cash_out trim banks (rung price - avg cost/unit) x qty sold,
        // the reentry buy moves cash by exactly -dollars, and the runner's TTP close banks whatever
        // is left at its own fill price minus its cost basis. Fees, slippage and funding are zeroed
        // (see overrides()), so this number is fully determined by the walk above, not tuned.
        $this->assertEqualsWithDelta(2_007_094.31, (float) $bt->ending_equity, 0.02);
    }

    public function test_the_stop_fires_off_the_moved_average_after_a_reentry_buy(): void
    {
        $this->setUpProduct();
        $def = $this->definePlugin();
        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        [$ts, , $fill] = $this->warmupAndEntry($from);

        // One rung (+1.6%, past the 1.5% target), a multi-bar retrace that both clears the
        // 0.75-point minimum and cools RSI(14) back under the not_overbought(70) gate reentry.when
        // also checks (see the comment on the other test in this file), arming a reentry that moves
        // the average up — then a crash well past the fail-safe from wherever that new average
        // landed. The direct-call engine test
        // (JsonPluginStrategyV2EngineTest::the_stop_moves_with_the_average_after_a_reentry_buy)
        // is what actually pins the boundary — this proves the same thing survives a real run.
        $ts = $this->walk($ts, $fill, [1.016, 1.010, 1.006, 1.006]);
        $ts->addHour();
        $crash = $fill * 0.90;
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $ts->copy(), 'open' => $fill * 1.002, 'high' => $fill * 1.002, 'low' => $crash, 'close' => $crash, 'volume' => 230]);
        $to = $ts->copy()->addHour();

        $bt = app(Backtester::class)->run('json', ['BTC-USD'], $from, $to, 2_000_000.0, $this->overrides($def['key']));

        $this->assertSame('done', $bt->status);
        $trade = $bt->trades[0];
        $this->assertSame('stop.pct_from_avg', $trade['rule']);
        $this->assertSame(1, $trade['adds'], 'the reentry buy fired before the stop did');
        $this->assertGreaterThan($fill, $trade['entry'], 'the average the stop measured from had already moved up off the reentry buy');

        // Closed form: SIZE puts in $100,000 at $fill (no fees/slippage), the ladder banks one small
        // rung, the reentry buy moves cash by -dollars, and the stop liquidates everything left at
        // the crash price. Fees, slippage and funding are zeroed (see overrides()), so this number
        // is fully determined by the walk above, not tuned.
        $this->assertEqualsWithDelta(1_990_018.05, (float) $bt->ending_equity, 0.02);
    }
}
