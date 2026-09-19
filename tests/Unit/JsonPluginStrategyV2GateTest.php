<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\DeskContext;
use App\Desk\Strategies\JsonPluginStrategy;
use App\Models\StrategyPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase C (docs/STRATEGY_SCHEMA_V2.md) landed the v2 engine: a saved
 * schema_version:2 plugin now runs like any other, through the same
 * definition()/scan()/vet()/size()/risk() path a v1 plugin uses.
 */
class JsonPluginStrategyV2GateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_v2_plugin_can_be_selected_for_a_run(): void
    {
        $v2 = json_decode(file_get_contents(base_path('resources/strategies/examples/smx-pi-take-profit-v2.json')), true);
        StrategyPlugin::create([
            'key' => $v2['key'],
            'name' => $v2['meta']['name'],
            'definition' => $v2,
            'current_version' => '1.0.0',
        ]);

        $strategy = new JsonPluginStrategy;
        $ctx = new DeskContext(['json' => ['plugin_key' => $v2['key']]], 'backtest');

        $def = $strategy->definition($ctx);

        $this->assertNotNull($def);
        $this->assertSame(2, $def['schema_version']);
        $this->assertSame($v2['key'], $def['key']);
    }
}
