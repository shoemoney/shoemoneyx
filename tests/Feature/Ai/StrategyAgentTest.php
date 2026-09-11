<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\AgentContext;
use App\Ai\ChatResponse;
use App\Ai\Contracts\ChatClient;
use App\Ai\StrategyAgent;
use App\Ai\Tools\CompareVersionsTool;
use App\Desk\Strategies\JsonPluginValidator;
use App\Models\AgentConversation;
use App\Models\StrategyPlugin;
use App\Models\StrategyPluginVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fixtures\FakeChatClient;
use Tests\TestCase;

class StrategyAgentTest extends TestCase
{
    use RefreshDatabase;

    /** A minimal legacy-shape definition that must pass JsonPluginValidator::validate() as-is. */
    private function validLegacyDefinition(): array
    {
        $def = [
            'key' => 'test-strat',
            'name' => 'Test Strategy',
            'description' => 'a test strategy',
            'version' => 1,
            'base' => 'custom',
            'scan' => [
                'max_candidates' => 5,
                'filters' => [['field' => 'price', 'op' => '>', 'value' => 0]],
            ],
            'vet' => ['rules' => [['field' => 'spread_bps', 'op' => '<=', 'value' => 15]]],
            'size' => ['kelly_fraction' => 0.25, 'max_pct_book' => 6.0],
            'risk' => ['rules' => [['field' => 'volume_ratio_6h', 'op' => '<', 'value' => 0.2, 'action' => 'close']]],
        ];

        $result = JsonPluginValidator::validate($def);
        $this->assertTrue($result['valid'], 'fixture definition must be valid: '.json_encode($result['errors']));

        return $def;
    }

    private function makeAgent(FakeChatClient $fake): StrategyAgent
    {
        $this->app->instance(ChatClient::class, $fake);

        return $this->app->make(StrategyAgent::class);
    }

    public function test_strategy_json_tool_call_saves_a_new_plugin_and_version(): void
    {
        $conversation = AgentConversation::create(['messages' => [], 'phase' => 1]);
        $def = $this->validLegacyDefinition();

        $fake = new FakeChatClient([
            new ChatResponse(
                content: null,
                toolCalls: [['id' => 'call_1', 'name' => 'strategy_json', 'arguments' => ['definition' => $def]]],
                model: 'fake',
            ),
            new ChatResponse(content: 'Saved as v1.0.0.', toolCalls: [], model: 'fake'),
        ]);
        $agent = $this->makeAgent($fake);

        $result = $agent->turn($conversation->id, 'here is my strategy');

        $this->assertSame('Saved as v1.0.0.', $result['content']);
        $this->assertSame(1, StrategyPlugin::count());
        $plugin = StrategyPlugin::first();
        $this->assertSame('1.0.0', $plugin->current_version);
        $this->assertSame(1, StrategyPluginVersion::where('strategy_plugin_id', $plugin->id)->count());
    }

    public function test_set_phase_tool_call_updates_the_conversation_phase(): void
    {
        $conversation = AgentConversation::create(['messages' => [], 'phase' => 1]);

        $fake = new FakeChatClient([
            new ChatResponse(
                content: null,
                toolCalls: [['id' => 'call_1', 'name' => 'set_phase', 'arguments' => ['phase' => 3]]],
                model: 'fake',
            ),
            new ChatResponse(content: 'Moving to trigger.', toolCalls: [], model: 'fake'),
        ]);
        $agent = $this->makeAgent($fake);

        $agent->turn($conversation->id, 'setup is done');

        $this->assertSame(3, AgentConversation::find($conversation->id)->phase);
    }

    public function test_tool_execution_loop_stops_at_six_total_executions(): void
    {
        $conversation = AgentConversation::create(['messages' => [], 'phase' => 1]);

        $phases = [2, 3, 4, 5, 6, 7, 1, 2];
        $responses = array_map(
            fn (int $phase, int $i) => new ChatResponse(
                content: null,
                toolCalls: [['id' => "call_{$i}", 'name' => 'set_phase', 'arguments' => ['phase' => $phase]]],
                model: 'fake',
            ),
            $phases,
            array_keys($phases),
        );
        $fake = new FakeChatClient($responses);
        $agent = $this->makeAgent($fake);

        $result = $agent->turn($conversation->id, 'go');

        $this->assertCount(6, $result['tool_events']);
        // 7 chat() calls, not 6: the loop only detects the cap when it examines the
        // 7th response's tool call (executed=6 >= MAX_TOOL_CALLS), so that 7th round's
        // chat() has already happened by the time the call is skipped instead of run.
        $this->assertCount(7, $fake->calls);
    }

    public function test_history_is_capped_at_forty_messages_keeping_the_newest(): void
    {
        $seeded = [];
        for ($i = 1; $i <= 45; $i++) {
            $seeded[] = ['role' => 'user', 'content' => "msg {$i}"];
        }
        $conversation = AgentConversation::create(['messages' => $seeded, 'phase' => 1]);

        $fake = new FakeChatClient([
            new ChatResponse(content: 'noted.', toolCalls: [], model: 'fake'),
        ]);
        $agent = $this->makeAgent($fake);

        $agent->turn($conversation->id, 'the newest message');

        $messages = AgentConversation::find($conversation->id)->messages;
        $this->assertCount(40, $messages);
        $this->assertSame('the newest message', $messages[38]['content']);
        $this->assertSame('noted.', $messages[39]['content']);
        $this->assertSame('msg 8', $messages[0]['content'], 'the oldest 7 of 45 seeded messages must have been dropped');
    }

    public function test_compare_versions_tool_reports_the_actual_seeded_diff(): void
    {
        $plugin = StrategyPlugin::create([
            'key' => 'diff-test',
            'name' => 'Diff Test',
            'definition' => ['key' => 'diff-test', 'name' => 'Diff Test', 'version' => 1],
            'current_version' => '1.1.0',
        ]);
        StrategyPluginVersion::create([
            'strategy_plugin_id' => $plugin->id,
            'version' => '1.0.0',
            'definition' => ['key' => 'diff-test', 'name' => 'Diff Test', 'version' => 1, 'scan' => ['max_candidates' => 5]],
        ]);
        StrategyPluginVersion::create([
            'strategy_plugin_id' => $plugin->id,
            'version' => '1.1.0',
            'definition' => ['key' => 'diff-test', 'name' => 'Diff Test v2', 'version' => 1, 'scan' => ['max_candidates' => 10]],
        ]);

        $tool = new CompareVersionsTool;
        $context = new AgentContext($conversationId = 1, $plugin->id);
        $result = $tool->run(['plugin_id' => $plugin->id, 'from' => '1.0.0', 'to' => '1.1.0'], $context);

        $this->assertSame('1.0.0', $result['from']);
        $this->assertSame('1.1.0', $result['to']);
        $this->assertSame(
            ['path' => 'name', 'from' => 'Diff Test', 'to' => 'Diff Test v2'],
            $result['diff']['changed'][0],
        );
        $this->assertSame(
            ['path' => 'scan.max_candidates', 'from' => 5, 'to' => 10],
            $result['diff']['changed'][1],
        );
        $this->assertSame([], $result['diff']['added']);
        $this->assertSame([], $result['diff']['removed']);
    }
}
