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
 * A saved schema_version:2 plugin has no engine yet (docs/STRATEGY_SCHEMA_V2.md,
 * phase C) — this asserts it cannot be quietly picked up by a backtest or a
 * live/paper run through JsonPluginStrategy, which is every run path there is.
 */
class JsonPluginStrategyV2GateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_v2_plugin_cannot_be_selected_for_a_run(): void
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

        $this->expectException(\LogicException::class);

        $strategy->definition($ctx);
    }
}
