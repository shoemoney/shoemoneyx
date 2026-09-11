<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Backtester;
use App\Desk\Contracts\Strategy;
use App\Desk\Data\Bank;
use App\Desk\Data\Candidate;
use App\Desk\Data\ProductStats;
use App\Desk\Data\RiskDecision;
use App\Desk\Data\SizeDecision;
use App\Desk\Data\Verdict;
use App\Desk\DeskContext;
use App\Models\Candle;
use App\Models\Position;
use App\Models\Product;
use App\Services\Market\CandleStore;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Review finding 8: at each hour boundary the backtester used to feed fromBars() bars with
 * start <= ts, so the forming hour's eventual close/high/low/volume leaked into every sub-hour
 * step taken before that hour actually closed. Fixed by excluding any bar not yet closed
 * (start + 3600 <= ts) from the hourly stats a sub-hour step is built from.
 */
class BacktesterCausalityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, ProductStats> everything the spy strategy's scan() saw for BTC-USD, keyed by unix ts */
    private function runSpy(Carbon $from, Carbon $to, float $futureClose, float $futureHigh, float $futureVolume): array
    {
        config(['cache.default' => 'array']);
        Product::firstOrCreate(['product_id' => 'BTC-USD'], ['base_currency' => 'BTC', 'quote_currency' => 'USD']);
        Candle::query()->where('product_id', 'BTC-USD')->delete();
        CandleStore::forgetLocal();

        // The bar covering the whole window: starts at $from (second 0), does not close until
        // $from + 1h. Its eventual close/high/volume must never be visible before then.
        Candle::create([
            'product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $from,
            'open' => 100, 'high' => $futureHigh, 'low' => 90, 'close' => $futureClose, 'volume' => $futureVolume,
        ]);
        // What is actually known minute by minute so far: flat at 100, a token amount of volume.
        for ($m = 0; $m <= 2; $m++) {
            Candle::create([
                'product_id' => 'BTC-USD', 'timeframe' => '1m', 'candle_start' => $from->copy()->addMinutes($m),
                'open' => 100, 'high' => 100, 'low' => 100, 'close' => 100, 'volume' => 1,
            ]);
        }

        $captured = [];
        $spy = new class($captured) implements Strategy
        {
            public function __construct(public array $captured = []) {}

            public function key(): string
            {
                return 'causality_spy';
            }

            public function name(): string
            {
                return 'Causality Spy';
            }

            public function defaults(): array
            {
                return [];
            }

            public function scan(array $universe, DeskContext $ctx): array
            {
                foreach ($universe as $s) {
                    if ($s->productId === 'BTC-USD') {
                        $this->captured[$ctx->now()->getTimestamp()] = $s;
                    }
                }

                return [];
            }

            public function vet(Candidate $c, Bank $bank, DeskContext $ctx): Verdict
            {
                return Verdict::pass($c, []);
            }

            public function size(Verdict $v, Bank $bank, DeskContext $ctx): SizeDecision
            {
                return new SizeDecision($v, 0.0, 0.0, 0.0, true, false, 'causality spy never sizes');
            }

            public function risk(Position $p, ProductStats $s, DeskContext $ctx): RiskDecision
            {
                return RiskDecision::hold();
            }
        };

        config(['desk.strategies.causality_spy' => $spy::class]);
        $this->app->instance($spy::class, $spy);

        app(Backtester::class)->run('causality_spy', ['BTC-USD'], $from, $to, 10000.0, [
            'backtest.step' => '1m',
            'paper.slippage_bps' => 0,
            'fees.funding_hourly_pct' => 0,
        ]);

        return $spy->captured;
    }

    /** The review's own reproduction: a bar starting at second 0 with eventual close 200 and
     *  volume 1,000 (h1 volume-usd = 1,000 * avg(100, 200) = $150,000) must not be visible at
     *  second 60 -- 59 minutes before it closes. */
    public function test_the_forming_hours_close_and_volume_are_not_visible_before_it_closes(): void
    {
        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        $captured = $this->runSpy($from, $from->copy()->addMinutes(2), futureClose: 200.0, futureHigh: 250.0, futureVolume: 1000.0);

        $atSecond60 = $captured[$from->copy()->addMinute()->getTimestamp()] ?? null;
        $this->assertNotNull($atSecond60, 'the spy should have seen a universe row for BTC-USD at second 60');
        $this->assertNotEqualsWithDelta(200.0, $atSecond60->price, 0.01, 'the forming hour close leaked into a sub-hour decision');
        $this->assertNotEqualsWithDelta(150000.0, $atSecond60->volumeH1Usd, 1.0, 'the forming hour volume leaked into a sub-hour decision');
    }

    /** General causality: two runs identical up to the decision timestamp, differing only in data
     *  for the bar that has not closed yet, must produce identical decisions at that timestamp. */
    public function test_changing_future_candle_data_does_not_alter_an_earlier_decision(): void
    {
        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        $to = $from->copy()->addMinutes(2);

        $a = $this->runSpy($from, $to, futureClose: 200.0, futureHigh: 250.0, futureVolume: 1000.0);
        $b = $this->runSpy($from, $to, futureClose: 9999.0, futureHigh: 9999.0, futureVolume: 999999.0);

        $ts = $from->copy()->addMinute()->getTimestamp();
        $this->assertArrayHasKey($ts, $a);
        $this->assertArrayHasKey($ts, $b);
        $this->assertSame($a[$ts]->price, $b[$ts]->price, 'changing a not-yet-closed bar altered an already-made decision');
        $this->assertSame($a[$ts]->volumeH1Usd, $b[$ts]->volumeH1Usd);
    }
}
