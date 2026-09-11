<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\BacktestController;
use App\Jobs\RunBacktest;
use App\Models\Backtest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Finding 24 (typed boolean overrides) and finding 19 (unbounded, un-paginated history).
 */
class BacktestApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([RunBacktest::class]); // never actually run the backtest job in these tests
    }

    public function test_literal_true_false_strings_become_real_booleans(): void
    {
        $bt = $this->postJson('/api/backtests', [
            'strategy' => 'mr',
            'products' => ['BTC-USD'],
            'params' => ['mr.allow_shorts' => 'false', 'mr.short.allow_shorts' => 'true'],
        ])->assertStatus(202)->json();

        $row = Backtest::findOrFail($bt['id']);
        $this->assertSame(false, $row->params['mr.allow_shorts'], 'the literal string "false" must not survive as a truthy string');
        $this->assertSame(true, $row->params['mr.short.allow_shorts']);
    }

    public function test_zero_and_one_strings_become_booleans(): void
    {
        $bt = $this->postJson('/api/backtests', [
            'strategy' => 'mr',
            'products' => ['BTC-USD'],
            'params' => ['mr.allow_shorts' => '0', 'perps.whole_contracts' => '1'],
        ])->assertStatus(202)->json();

        $row = Backtest::findOrFail($bt['id']);
        $this->assertSame(false, $row->params['mr.allow_shorts']);
        $this->assertSame(true, $row->params['perps.whole_contracts']);
    }

    public function test_on_off_strings_become_booleans_and_numeric_strings_become_numbers(): void
    {
        $bt = $this->postJson('/api/backtests', [
            'strategy' => 'mr',
            'products' => ['BTC-USD'],
            'params' => ['perps.margin' => 'ON', 'mr.leverage' => '3', 'size.kelly_cap_pct' => '0.04', 'mr.timeframe' => '2m'],
        ])->assertStatus(202)->json();

        $row = Backtest::findOrFail($bt['id']);
        $this->assertSame(true, $row->params['perps.margin']);
        $this->assertSame(3, $row->params['mr.leverage']);
        $this->assertSame(0.04, $row->params['size.kelly_cap_pct']);
        $this->assertSame('2m', $row->params['mr.timeframe'], 'a genuine string override must not be mangled');
    }

    public function test_unknown_override_key_is_rejected(): void
    {
        $this->postJson('/api/backtests', [
            'strategy' => 'mr',
            'products' => ['BTC-USD'],
            'params' => ['not_a_real_namespace.field' => 'true'],
        ])->assertStatus(422)->assertJsonValidationErrors('params');

        Bus::assertNotDispatched(RunBacktest::class);
    }

    public function test_malformed_numeric_value_is_rejected(): void
    {
        $this->postJson('/api/backtests', [
            'strategy' => 'mr',
            'products' => ['BTC-USD'],
            'params' => ['mr.leverage' => '1.2.3'],
        ])->assertStatus(422)->assertJsonValidationErrors('params');

        Bus::assertNotDispatched(RunBacktest::class);
    }

    public function test_normalize_overrides_rejects_an_unrecognized_top_level_key_directly(): void
    {
        $this->expectException(ValidationException::class);
        BacktestController::normalizeOverrides(['not_a_real_namespace' => 'true']);
    }

    public function test_index_is_paginated_and_bounded_against_a_large_fixture(): void
    {
        for ($i = 0; $i < 500; $i++) {
            Backtest::create([
                'strategy' => 'mr', 'products' => ['BTC-USD'], 'from' => now()->subDays(2), 'to' => now()->subDay(),
                'starting_cash' => 1000, 'status' => 'done', 'params' => [],
                'stats' => ['total_return_pct' => 1.0, 'days' => 30],
            ]);
        }

        $r = $this->getJson('/api/backtests')->assertOk()->json();
        $this->assertLessThanOrEqual(50, count($r['data']), 'default page size is 50');
        $this->assertTrue($r['has_more']);
        $this->assertNotNull($r['next_cursor']);

        $r2 = $this->getJson('/api/backtests?limit=200')->assertOk()->json();
        $this->assertCount(200, $r2['data']);

        $r3 = $this->getJson('/api/backtests?limit=9999')->assertOk()->json();
        $this->assertLessThanOrEqual(200, count($r3['data']), 'limit is capped at 200 regardless of what is requested');
    }

    public function test_index_excludes_farm_rows_by_default_and_include_farm_reveals_them(): void
    {
        Backtest::create(['strategy' => 'mr', 'products' => ['BTC-USD'], 'from' => now()->subDays(2), 'to' => now()->subDay(), 'starting_cash' => 1000, 'status' => 'done', 'params' => []]);
        Backtest::create(['strategy' => 'mr', 'products' => ['BTC-USD'], 'from' => now()->subDays(2), 'to' => now()->subDay(), 'starting_cash' => 1000, 'status' => 'done', 'params' => ['_opt' => ['coin' => 'BTC-USD', 'side' => 'long', 'window' => 'test']]]);

        $r = $this->getJson('/api/backtests')->assertOk()->json();
        $this->assertCount(1, $r['data'], 'the farm/optimizer row is excluded by default');

        $r2 = $this->getJson('/api/backtests?include=farm')->assertOk()->json();
        $this->assertCount(2, $r2['data']);
    }

    public function test_show_returns_only_the_one_selected_job(): void
    {
        $a = Backtest::create(['strategy' => 'mr', 'products' => ['BTC-USD'], 'from' => now()->subDays(2), 'to' => now()->subDay(), 'starting_cash' => 1000, 'status' => 'running', 'params' => []]);
        Backtest::create(['strategy' => 'mr', 'products' => ['ETH-USD'], 'from' => now()->subDays(2), 'to' => now()->subDay(), 'starting_cash' => 1000, 'status' => 'done', 'params' => []]);

        $r = $this->getJson("/api/backtests/{$a->id}")->assertOk()->json();
        $this->assertSame($a->id, $r['id']);
        $this->assertArrayNotHasKey('data', $r, 'show() returns the single row, not a paginated envelope');
    }
}
