<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Ai\AgentContext;
use App\Ai\Tools\ArenaScoreboardTool;
use App\Ai\Tools\StartArenaSeatTool;
use App\Models\ArenaSeat;
use App\Models\StrategyPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArenaAgentToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);
    }

    private function plugin(): StrategyPlugin
    {
        $definition = ['key' => 'rsi-dip', 'name' => 'RSI Dip', 'version' => 1, 'scan' => []];
        $plugin = StrategyPlugin::create(['key' => 'rsi-dip', 'name' => 'RSI Dip', 'definition' => $definition, 'current_version' => '1.0.0']);
        $plugin->versions()->create(['version' => '1.0.0', 'definition' => $definition]);

        return $plugin;
    }

    public function test_start_arena_seat_with_an_explicit_version_id(): void
    {
        $plugin = $this->plugin();
        $version = $plugin->versions()->first();
        $ctx = new AgentContext(conversationId: 1);

        $result = (new StartArenaSeatTool)->run(['version_id' => $version->id, 'label' => 'From the agent', 'cash' => 250], $ctx);

        $this->assertSame('From the agent', $result['label']);
        $this->assertSame('active', $result['status']);
        $this->assertDatabaseHas('arena_seats', ['id' => $result['id'], 'strategy_plugin_version_id' => $version->id, 'starting_cash' => 250]);
    }

    public function test_start_arena_seat_falls_back_to_the_plugin_just_saved_this_turn(): void
    {
        $plugin = $this->plugin();
        $ctx = new AgentContext(conversationId: 1, pluginId: $plugin->id);

        $result = (new StartArenaSeatTool)->run([], $ctx);

        $this->assertSame($plugin->versions()->first()->id, ArenaSeat::findOrFail($result['id'])->strategy_plugin_version_id);
    }

    public function test_start_arena_seat_without_a_version_or_a_saved_plugin_errors(): void
    {
        $result = (new StartArenaSeatTool)->run([], new AgentContext(conversationId: 1));

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, ArenaSeat::count());
    }

    public function test_arena_scoreboard_tool_reads_the_same_shape_the_api_returns(): void
    {
        ArenaSeat::create(['label' => 'A', 'strategy_key' => 'mr', 'starting_cash' => 1000, 'status' => 'active', 'is_champion' => true, 'started_at' => now()]);

        $result = (new ArenaScoreboardTool)->run([], new AgentContext(conversationId: 1));

        $this->assertCount(1, $result['seats']);
        $this->assertSame('A', $result['seats'][0]['label']);
        $this->assertTrue($result['seats'][0]['is_champion']);
    }
}
