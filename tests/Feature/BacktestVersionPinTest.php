<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\DeskContext;
use App\Desk\Strategies\JsonPluginStrategy;
use App\Jobs\RunBacktest;
use App\Models\Backtest;
use App\Models\StrategyPlugin;
use App\Models\StrategyPluginVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class BacktestVersionPinTest extends TestCase
{
    use RefreshDatabase;

    private function definition(int $rsiThreshold): array
    {
        return [
            'key' => 'rsi-dip',
            'name' => 'RSI Dip',
            'version' => 1,
            'suggest' => ['products' => ['BTC-USD'], 'days' => 10, 'cash' => 500],
            'scan' => ['filters' => [['field' => 'indicators.rsi14', 'op' => '<', 'value' => $rsiThreshold]]],
        ];
    }

    public function test_backtest_pins_the_current_version_and_records_the_fk(): void
    {
        Bus::fake([RunBacktest::class]);
        $this->postJson('/api/strategy-plugins', ['definition' => $this->definition(35)])->assertCreated();
        $plugin = StrategyPlugin::where('key', 'rsi-dip')->sole();

        $bt = $this->postJson("/api/strategy-plugins/{$plugin->id}/backtest", [])->assertStatus(202)->json();

        $row = Backtest::findOrFail($bt['id']);
        $this->assertNotNull($row->strategy_plugin_version_id);
        $pinned = StrategyPluginVersion::findOrFail($row->strategy_plugin_version_id);
        $this->assertSame('1.0.0', $pinned->version);
        $this->assertSame('1.0.0', $row->strategy_version);
    }

    public function test_editing_the_plugin_does_not_change_an_already_pinned_backtest(): void
    {
        Bus::fake([RunBacktest::class]);
        $this->postJson('/api/strategy-plugins', ['definition' => $this->definition(35)])->assertCreated();
        $plugin = StrategyPlugin::where('key', 'rsi-dip')->sole();

        $firstBt = $this->postJson("/api/strategy-plugins/{$plugin->id}/backtest", [])->assertStatus(202)->json();

        // Edit the plugin — a new version, current_version moves to 1.0.1.
        $this->postJson('/api/strategy-plugins', ['definition' => $this->definition(40)])->assertCreated();
        $plugin->refresh();
        $this->assertSame('1.0.1', $plugin->current_version);

        $secondBt = $this->postJson("/api/strategy-plugins/{$plugin->id}/backtest", [])->assertStatus(202)->json();

        $first = Backtest::findOrFail($firstBt['id']);
        $second = Backtest::findOrFail($secondBt['id']);
        $this->assertSame('1.0.0', $first->strategy_version);
        $this->assertSame('1.0.1', $second->strategy_version);
        $this->assertNotSame($first->strategy_plugin_version_id, $second->strategy_plugin_version_id);

        // The runner reads the pinned definition, not the mutable plugin's current one.
        $ctx = new DeskContext(
            ['json' => ['plugin_version_id' => $first->strategy_plugin_version_id]],
            'backtest',
        );
        $def = (new JsonPluginStrategy)->definition($ctx);
        $this->assertSame(35, $def['trigger']['rules'][0]['value']);
    }

    public function test_generic_backtest_endpoint_also_pins_a_version(): void
    {
        Bus::fake([RunBacktest::class]);
        $this->postJson('/api/strategy-plugins', ['definition' => $this->definition(35)])->assertCreated();

        $res = $this->postJson('/api/backtests', [
            'strategy' => 'json',
            'params' => ['json' => ['plugin_key' => 'rsi-dip']],
        ])->assertStatus(202)->json();

        $row = Backtest::findOrFail($res['id']);
        $this->assertSame('1.0.0', $row->strategy_version);
        $this->assertSame($row->strategy_plugin_version_id, $row->params['json.plugin_version_id']);
    }
}
