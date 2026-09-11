<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BankSnapshot;
use App\Models\Candle;
use App\Models\DeskEvent;
use App\Models\DeskRun;
use App\Models\Position;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeskApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);
        Http::fake([
            'api.coinbase.com/api/v3/brokerage/market/products*' => Http::response(['products' => [
                ['product_id' => 'BTC-USD', 'base_currency_id' => 'BTC', 'quote_currency_id' => 'USD', 'product_type' => 'SPOT', 'status' => 'online', 'price' => '80000', 'volume_24h' => '1000', 'price_percentage_change_24h' => '1.5', 'base_increment' => '0.00000001', 'quote_increment' => '0.01'],
            ]]),
            'api.coinbase.com/api/v3/brokerage/market/products/*/ticker*' => Http::response(['best_bid' => '79990', 'best_ask' => '80010', 'trades' => [
                ['trade_id' => '1', 'price' => '80000', 'size' => '0.1', 'side' => 'BUY', 'time' => now()->toIso8601String()],
            ]]),
            'api.coinbase.com/api/v3/brokerage/market/product_book*' => Http::response(['pricebook' => ['bids' => [['price' => '79990', 'size' => '5']], 'asks' => [['price' => '80010', 'size' => '5']]]]),
            'api.coinbase.com/*' => Http::response(['candles' => []]),
        ]);
    }

    public function test_status_endpoint_reports_health_and_paper_bank(): void
    {
        $this->getJson('/api/status')
            ->assertOk()
            ->assertJsonPath('health.mode', 'paper')
            ->assertJsonPath('bank.cash', 1000)
            ->assertJsonPath('open_positions', 0);
    }

    public function test_settings_can_be_overridden_and_reset(): void
    {
        $this->putJson('/api/settings', ['key' => 'size.kelly_cap_pct', 'value' => '0.04'])->assertOk();
        $this->assertSame(0.04, $this->getJson('/api/settings')->assertOk()->json('params')['size.kelly_cap_pct']);
        $this->deleteJson('/api/settings/size.kelly_cap_pct')->assertOk();
        $this->assertSame(0.06, $this->getJson('/api/settings')->json('params')['size.kelly_cap_pct']);
    }

    /**
     * Finding 4: updateSetting used to only understand "true"/"false" — "on"/"off"/"no"/"1"/"0"
     * on the live running desk's settings endpoint used to be stored as truthy non-empty strings
     * instead of real booleans. Now routed through the same App\Support\ParamNormalizer as the
     * backtest override path, so it must accept the full vocabulary, and — same as the backtest
     * path — must not mangle a numeric-looking STRING knob.
     */
    public function test_update_setting_accepts_on_off_yes_no_as_booleans(): void
    {
        $this->putJson('/api/settings', ['key' => 'perps.whole_contracts', 'value' => 'off'])->assertOk();
        $this->assertSame(false, $this->getJson('/api/settings')->json('params')['perps.whole_contracts']);

        $this->putJson('/api/settings', ['key' => 'perps.whole_contracts', 'value' => 'no'])->assertOk();
        $this->assertSame(false, $this->getJson('/api/settings')->json('params')['perps.whole_contracts']);

        $this->putJson('/api/settings', ['key' => 'perps.whole_contracts', 'value' => 'on'])->assertOk();
        $this->assertSame(true, $this->getJson('/api/settings')->json('params')['perps.whole_contracts']);
    }

    public function test_update_setting_keeps_a_string_typed_knob_as_a_string(): void
    {
        $this->putJson('/api/settings', ['key' => 'mr.timeframe', 'value' => '1'])->assertOk();
        $this->assertSame('1', $this->getJson('/api/settings')->json('params')['mr.timeframe'], 'a string-typed knob must not be coerced into an int/bool');
    }

    public function test_product_sync_and_udf_datafeed(): void
    {
        $this->artisan('market:sync-products')->assertSuccessful();
        $this->assertSame(1, Product::tracked()->count());

        $t = now()->startOfHour();
        foreach (range(0, 5) as $i) {
            Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $t->copy()->subHours(5 - $i), 'open' => 1, 'high' => 2, 'low' => 1, 'close' => 1.5, 'volume' => 10]);
        }

        $this->getJson('/api/udf/config')->assertOk()->assertJsonPath('supports_marks', true);
        $this->getJson('/api/udf/symbols?symbol=BTC-USD')->assertOk()->assertJsonPath('ticker', 'BTC-USD');
        $r = $this->getJson('/api/udf/history?symbol=BTC-USD&resolution=60&from='.$t->copy()->subHours(6)->timestamp.'&to='.$t->timestamp)->assertOk();
        $this->assertSame('ok', $r->json('s'));
        $this->assertCount(6, $r->json('t'));
    }

    public function test_a_cycle_runs_end_to_end_in_paper_mode(): void
    {
        $this->artisan('market:sync-products')->assertSuccessful();
        $t = now()->startOfHour();
        foreach (range(0, 47) as $i) {
            Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $t->copy()->subHours(47 - $i), 'open' => 80000, 'high' => 80100, 'low' => 79900, 'close' => 80050, 'volume' => 10]);
        }
        $this->postJson('/api/desk/cycle')->assertOk()->assertJsonPath('run.status', 'done')->assertJsonPath('run.products_scanned', 1);
        $this->getJson('/api/runs/latest')->assertOk();
        $this->getJson('/api/events')->assertOk();
    }

    public function test_settings_are_available_without_a_token_or_with_an_outdated_token(): void
    {
        config(['desk.api_token' => 'secret']);
        $this->getJson('/api/settings')->assertOk();
        $this->withHeader('X-Desk-Token', 'outdated')->getJson('/api/settings')->assertOk();
    }

    public function test_dashboard_loads_real_data_without_a_token_or_with_an_outdated_token(): void
    {
        config(['desk.api_token' => 'secret']);
        $position = Position::create(['mode' => 'paper', 'strategy' => 'mr', 'product_id' => 'BTC-USD',
            'status' => 'open', 'side' => 'long', 'quantity' => 1, 'entry_price' => 80000,
            'entry_usd' => 80000, 'last_price' => 80000, 'opened_at' => now()]);
        BankSnapshot::create(['mode' => 'paper', 'cash' => 1000, 'equity' => 1200,
            'positions_value' => 200, 'locked' => 0, 'free_cash' => 800, 'taken_at' => now()]);
        $run = DeskRun::create(['mode' => 'paper', 'strategy' => 'mr', 'status' => 'done', 'started_at' => now()]);
        $event = DeskEvent::create(['desk_run_id' => $run->id, 'agent' => 'SCAN', 'message' => 'Account scan complete']);

        foreach ([null, 'outdated-token'] as $token) {
            $headers = $token === null ? [] : ['X-Desk-Token' => $token];
            $this->getJson('/api/status', $headers)->assertOk()->assertJsonPath('health.mode', 'paper')->assertJsonPath('open_positions', 1);
            $this->getJson('/api/bank/history?hours=168', $headers)->assertOk()->assertJsonPath('0.equity', 1200);
            $this->getJson('/api/positions?status=open', $headers)->assertOk()->assertJsonPath('0.id', $position->id);
            $this->getJson('/api/runs/latest', $headers)->assertOk()->assertJsonPath('id', $run->id);
            $this->getJson('/api/events?limit=40', $headers)->assertOk()->assertJsonPath('0.id', $event->id);
        }

        $this->assertSame('open', $position->fresh()->status);
    }

    public function test_dashboard_reads_do_not_execute_trades_or_change_settings(): void
    {
        $position = Position::create(['mode' => 'paper', 'strategy' => 'mr', 'product_id' => 'BTC-USD',
            'status' => 'open', 'side' => 'long', 'quantity' => 1, 'entry_price' => 100,
            'entry_usd' => 100, 'opened_at' => now()]);

        foreach (['/api/status', '/api/positions?status=open', '/api/runs/latest', '/api/events', '/api/settings'] as $path) {
            $this->getJson($path)->assertOk();
        }

        $this->assertSame('open', $position->fresh()->status);
        $this->assertDatabaseCount('fills', 0);
        $this->assertDatabaseCount('desk_runs', 0);
        $this->assertDatabaseCount('settings', 0);
    }
}
