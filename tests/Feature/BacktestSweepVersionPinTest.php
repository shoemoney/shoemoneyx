<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\DeskOptimize;
use App\Console\Commands\DeskSweep;
use App\Desk\Backtester;
use App\Desk\DeskContext;
use App\Desk\Strategies\JsonPluginStrategy;
use App\Jobs\RunBacktest;
use App\Models\Backtest;
use App\Models\Product;
use App\Models\StrategyPlugin;
use App\Models\StrategyPluginVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Sweep (DeskSweep::queueOne, the --queue path's per-combination write) and farm/optimizer
 * (DeskOptimize::reuseOrQueue) backtests now pin a JSON plugin's version the same way the two
 * API entry points already did (BacktestVersionPin) — every row carries the FK at dispatch time,
 * and editing the plugin afterward never changes what an already-created row's runner reads.
 */
class BacktestSweepVersionPinTest extends TestCase
{
    use RefreshDatabase;

    private function definition(int $rsiThreshold): array
    {
        return [
            'key' => 'sweep-rsi-dip',
            'name' => 'Sweep RSI Dip',
            'version' => 1,
            'scan' => ['filters' => [['field' => 'indicators.rsi14', 'op' => '<', 'value' => $rsiThreshold]]],
        ];
    }

    public function test_sweep_queue_one_writes_the_fk_and_pins_json_plugin_version_id(): void
    {
        Bus::fake([RunBacktest::class]);
        $this->postJson('/api/strategy-plugins', ['definition' => $this->definition(35)])->assertCreated();
        $plugin = StrategyPlugin::where('key', 'sweep-rsi-dip')->sole();
        $v1 = StrategyPluginVersion::where('strategy_plugin_id', $plugin->id)->where('version', $plugin->current_version)->sole();

        $bt = DeskSweep::queueOne('json', ['json.plugin_key' => 'sweep-rsi-dip'], ['BTC-USD'], now()->subDays(10), now(), 1000);

        $this->assertSame($v1->id, $bt->strategy_plugin_version_id);
        $this->assertSame($v1->id, $bt->params['json.plugin_version_id']);
    }

    public function test_a_later_plugin_edit_does_not_change_what_an_already_queued_sweep_row_runs(): void
    {
        Bus::fake([RunBacktest::class]);
        $this->postJson('/api/strategy-plugins', ['definition' => $this->definition(35)])->assertCreated();

        $first = DeskSweep::queueOne('json', ['json.plugin_key' => 'sweep-rsi-dip'], ['BTC-USD'], now()->subDays(10), now(), 1000);

        // Edit the plugin — current_version moves, a new immutable version row is created.
        $this->postJson('/api/strategy-plugins', ['definition' => $this->definition(40)])->assertCreated();

        $second = DeskSweep::queueOne('json', ['json.plugin_key' => 'sweep-rsi-dip'], ['BTC-USD'], now()->subDays(10), now(), 1000);

        $this->assertNotSame($first->strategy_plugin_version_id, $second->strategy_plugin_version_id);

        // The FIRST row's own pinned version is untouched by the edit, and the runner reads that
        // pinned definition (threshold 35), never the plugin's now-current one (threshold 40).
        $ctx = new DeskContext(['json' => ['plugin_version_id' => $first->strategy_plugin_version_id]], 'backtest');
        $def = (new JsonPluginStrategy)->definition($ctx);
        $this->assertSame(35, $def['trigger']['rules'][0]['value']);

        $ctx2 = new DeskContext(['json' => ['plugin_version_id' => $second->strategy_plugin_version_id]], 'backtest');
        $def2 = (new JsonPluginStrategy)->definition($ctx2);
        $this->assertSame(40, $def2['trigger']['rules'][0]['value']);
    }

    public function test_sweep_of_a_non_json_strategy_leaves_the_fk_null(): void
    {
        Bus::fake([RunBacktest::class]);
        $bt = DeskSweep::queueOne('mr', ['mr.entry_z' => 2.0], ['BTC-USD'], now()->subDays(10), now(), 1000);

        $this->assertNull($bt->strategy_plugin_version_id);
    }

    public function test_optimizer_reuse_or_queue_pins_json_plugin_version(): void
    {
        Bus::fake([RunBacktest::class]);
        $this->postJson('/api/strategy-plugins', ['definition' => $this->definition(35)])->assertCreated();
        $plugin = StrategyPlugin::where('key', 'sweep-rsi-dip')->sole();
        $v1 = StrategyPluginVersion::where('strategy_plugin_id', $plugin->id)->where('version', $plugin->current_version)->sole();

        $bt = DeskOptimize::reuseOrQueue([
            'strategy' => 'json', 'products' => ['BTC-USD'], 'from' => now()->subDays(5), 'to' => now(),
            'starting_cash' => 1000, 'params' => ['json.plugin_key' => 'sweep-rsi-dip'],
            'cache_key' => 'test-cache-key-'.uniqid(),
        ], 24);

        $this->assertSame($v1->id, $bt->strategy_plugin_version_id);
        $this->assertSame($v1->id, $bt->params['json.plugin_version_id']);
    }

    public function test_cli_backtest_run_pins_json_plugin_version(): void
    {
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);
        $this->postJson('/api/strategy-plugins', ['definition' => $this->definition(35)])->assertCreated();
        $plugin = StrategyPlugin::where('key', 'sweep-rsi-dip')->sole();
        $v1 = StrategyPluginVersion::where('strategy_plugin_id', $plugin->id)->where('version', $plugin->current_version)->sole();

        $bt = app(Backtester::class)->run('json', ['BTC-USD'], now()->subDays(2), now(), 1000, ['json.plugin_key' => 'sweep-rsi-dip']);

        $this->assertSame($v1->id, $bt->strategy_plugin_version_id);
        $this->assertSame($v1->id, $bt->params['json.plugin_version_id']);
    }
}
