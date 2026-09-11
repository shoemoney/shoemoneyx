<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Candle;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChartSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);
        Http::fake();
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD', 'status' => 'online']);
    }

    private function candle(int $at, string $tf = '1m', float $price = 100): void
    {
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => $tf,
            'candle_start' => gmdate('Y-m-d H:i:s', $at), 'open' => $price,
            'high' => $price + 2, 'low' => $price - 1, 'close' => $price + 1, 'volume' => 10]);
    }

    public function test_chart_menu_exposes_only_three_requested_intervals(): void
    {
        $this->getJson('/api/udf/config')->assertOk()->assertJsonPath('supported_resolutions', ['15S', '1', '5']);
        $this->getJson('/api/udf/symbols?symbol=BTC-USD')->assertOk()
            ->assertJsonPath('supported_resolutions', ['15S', '1', '5'])
            ->assertJsonPath('seconds_multipliers', ['15'])->assertJsonPath('has_daily', false);
    }

    public function test_empty_chart_and_indicator_requests_never_download_history(): void
    {
        $this->getJson('/api/udf/history?symbol=BTC-USD&resolution=1&from=0&countback=999999')
            ->assertOk()->assertJsonPath('s', 'no_data');
        $this->getJson('/api/smx?product=BTC-USD&tf=1m&bars=600')
            ->assertOk()->assertJsonPath('t', []);
        Http::assertNothingSent();
        $this->assertDatabaseCount('candles', 0);
    }

    public function test_five_minute_bars_are_aggregated_from_live_minute_store(): void
    {
        $at = 1788804000;
        foreach (range(0, 4) as $i) {
            $this->candle($at + $i * 60, '1m', 100 + $i);
        }
        $this->getJson('/api/udf/history?symbol=BTC-USD&resolution=5&from='.$at.'&to='.($at + 299))
            ->assertOk()->assertJsonPath('s', 'ok')->assertJsonPath('t', [$at])
            ->assertJsonPath('o.0', 100)->assertJsonPath('h.0', 106)
            ->assertJsonPath('l.0', 99)->assertJsonPath('c.0', 105)->assertJsonPath('v.0', 50);
        Http::assertNothingSent();
    }

    public function test_fifteen_second_bars_keep_original_tape_timestamps(): void
    {
        $at = 1788804000;
        $this->candle($at, '15s');
        $this->candle($at + 15, '15s', 102);
        $this->getJson('/api/udf/history?symbol=BTC-USD&resolution=15S&from='.$at.'&to='.($at + 29))
            ->assertOk()->assertJsonPath('t', [$at, $at + 15])->assertJsonPath('c', [101, 103]);
    }

    public function test_oversized_history_is_capped_at_latest_two_thousand_bars(): void
    {
        $at = 1788600000;
        $rows = [];
        foreach (range(0, 2004) as $i) {
            $rows[] = ['product_id' => 'BTC-USD', 'timeframe' => '1m', 'candle_start' => gmdate('Y-m-d H:i:s', $at + $i * 60),
                'open' => 100, 'high' => 102, 'low' => 99, 'close' => 101, 'volume' => 10];
        }
        foreach (array_chunk($rows, 100) as $batch) {
            Candle::insert($batch);
        }
        $r = $this->getJson('/api/udf/history?symbol=BTC-USD&resolution=1&from=0&to='.($at + 2004 * 60).'&countback=999999')->assertOk();
        $this->assertCount(2000, $r->json('t'));
        $this->assertSame($at + 5 * 60, $r->json('t.0'));
        $this->assertSame($at + 2004 * 60, $r->json('t.1999'));
        Http::assertNothingSent();
    }
}
