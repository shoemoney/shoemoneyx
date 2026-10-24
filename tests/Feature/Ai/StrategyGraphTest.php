<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\AgentContext;
use App\Ai\ChatResponse;
use App\Ai\Contracts\ChatClient;
use App\Ai\StrategyAgent;
use App\Ai\Tools\HubRegisterTool;
use App\Ai\Tools\RunBacktestTool;
use App\Ai\Tools\SetPhaseTool;
use App\Ai\Tools\StrategyJsonTool;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolRegistry;
use App\Jobs\RunBacktest;
use App\Models\AgentConversation;
use App\Models\Backtest;
use App\Models\StrategyPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use LangGraph\Agents\Nodes\ToolNode;
use Tests\Feature\Fixtures\FakeChatClient;
use Tests\TestCase;

class StrategyGraphTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_existing_incomplete_tool_batch_is_repaired_without_replaying_historical_tools(): void
    {
        $conversation = AgentConversation::create(['phase' => 5, 'messages' => [
            ['role' => 'user', 'content' => 'Continue the old turn.'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'completed', 'type' => 'function', 'function' => ['name' => 'set_phase', 'arguments' => '{"phase":5}']],
                ['id' => 'missing', 'type' => 'function', 'function' => ['name' => 'set_phase', 'arguments' => '{"phase":7}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'completed', 'content' => '{"phase":5}'],
        ]]);
        $client = new FakeChatClient([new ChatResponse('Ready to continue.', [], 'fake')]);

        $result = (new StrategyAgent($client, new ToolRegistry([new SetPhaseTool])))->turn($conversation->id, 'Try again.');

        $this->assertNull($result['error']);
        $this->assertSame([], $result['tool_events']);
        $this->assertSame(5, $conversation->fresh()->phase, 'Recovery must not execute the old phase-seven tool.');
        $sent = array_slice($client->calls[0]['messages'], 1);
        $this->assertSame(['user', 'assistant', 'tool', 'tool', 'user'], array_column($sent, 'role'));
        $this->assertSame('completed', $sent[2]['tool_call_id']);
        $this->assertSame('{"phase":5}', $sent[2]['content'], 'Previously recorded results must survive recovery.');
        $this->assertSame('missing', $sent[3]['tool_call_id']);
        $this->assertStringContainsString('not recorded', json_decode($sent[3]['content'], true)['error']);
        $this->assertSame('Try again.', $sent[4]['content']);
        $this->assertSame($sent, array_slice($conversation->fresh()->messages, 0, -1), 'Persist the repaired transcript for subsequent turns.');
    }

    public function test_orphan_tool_results_from_an_older_history_slice_do_not_reach_the_provider(): void
    {
        $conversation = AgentConversation::create(['phase' => 3, 'messages' => [
            ['role' => 'tool', 'tool_call_id' => 'lost-parent', 'content' => 'orphan payload'],
            ['role' => 'assistant', 'content' => 'Earlier response.'],
        ]]);
        $client = new FakeChatClient([new ChatResponse('Recovered.', [], 'fake')]);

        $result = (new StrategyAgent($client, new ToolRegistry))->turn($conversation->id, 'Continue.');

        $this->assertNull($result['error']);
        $this->assertSame([], $result['tool_events']);
        $this->assertSame(['system', 'assistant', 'user'], array_column($client->calls[0]['messages'], 'role'));
        $this->assertStringNotContainsString('orphan payload', json_encode([$client->calls, $conversation->fresh()->messages]));
        $this->assertSame(3, $conversation->fresh()->phase);
    }

    public function test_recovering_history_preserves_a_valid_completed_batch_unchanged(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'Earlier request.'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'valid', 'type' => 'function', 'function' => ['name' => 'set_phase', 'arguments' => '{"phase":4}']],
            ]],
            ['role' => 'tool', 'content' => '{"phase":4}', 'tool_call_id' => 'valid'],
            ['role' => 'assistant', 'content' => 'Phase four saved.'],
        ];
        $conversation = AgentConversation::create(['phase' => 4, 'messages' => $history]);
        $client = new FakeChatClient([new ChatResponse('Continuing.', [], 'fake')]);

        $result = (new StrategyAgent($client, new ToolRegistry([new SetPhaseTool])))->turn($conversation->id, 'Next.');

        $this->assertNull($result['error']);
        $this->assertSame([], $result['tool_events']);
        $this->assertSame([...$history, ['role' => 'user', 'content' => 'Next.']], array_slice($client->calls[0]['messages'], 1));
        $this->assertSame($history, array_slice($conversation->fresh()->messages, 0, count($history)));
        $this->assertSame(4, $conversation->fresh()->phase);
    }

    public function test_a_provider_reusing_a_prior_turn_tool_id_executes_the_new_call_with_a_fresh_id(): void
    {
        $conversation = AgentConversation::create(['phase' => 1, 'messages' => [
            ['role' => 'user', 'content' => 'Earlier turn.'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'reused', 'type' => 'function', 'function' => ['name' => 'set_phase', 'arguments' => '{"phase":1}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'reused', 'content' => '{"phase":1}'],
            ['role' => 'assistant', 'content' => 'Phase one.'],
        ]]);
        $client = new FakeChatClient([
            new ChatResponse(null, [['id' => 'reused', 'name' => 'set_phase', 'arguments' => ['phase' => 5]]], 'fake'),
            new ChatResponse('Phase five saved.', [], 'fake'),
        ]);

        $result = (new StrategyAgent($client, new ToolRegistry([new SetPhaseTool])))->turn($conversation->id, 'Move to phase five.');

        $this->assertNull($result['error']);
        $this->assertCount(1, $result['tool_events']);
        $this->assertSame(5, $conversation->fresh()->phase);
        $sent = $client->calls[1]['messages'];
        $newId = $sent[6]['tool_calls'][0]['id'];
        $this->assertNotSame('reused', $newId);
        $this->assertSame($newId, $sent[7]['tool_call_id']);
        $this->assertSame($newId, $conversation->fresh()->messages[5]['tool_calls'][0]['id']);
        $this->assertSame($newId, $conversation->fresh()->messages[6]['tool_call_id']);
    }

    public function test_repeated_ids_in_later_model_responses_in_one_turn_execute_each_new_call(): void
    {
        $conversation = AgentConversation::create(['phase' => 1, 'messages' => []]);
        $client = new FakeChatClient([
            new ChatResponse(null, [['id' => 'repeated', 'name' => 'set_phase', 'arguments' => ['phase' => 3]]], 'fake'),
            new ChatResponse(null, [['id' => 'repeated', 'name' => 'set_phase', 'arguments' => ['phase' => 5]]], 'fake'),
            new ChatResponse('Both phase changes saved.', [], 'fake'),
        ]);

        $result = (new StrategyAgent($client, new ToolRegistry([new SetPhaseTool])))->turn($conversation->id, 'Advance twice.');

        $this->assertNull($result['error']);
        $this->assertSame([3, 5], array_column(array_column($result['tool_events'], 'args'), 'phase'));
        $this->assertSame(5, $conversation->fresh()->phase);
        $messages = $conversation->fresh()->messages;
        $this->assertSame('repeated', $messages[1]['tool_calls'][0]['id'], 'Keep a provider ID when it is unique.');
        $this->assertNotSame('repeated', $messages[3]['tool_calls'][0]['id']);
        $this->assertSame($messages[3]['tool_calls'][0]['id'], $messages[4]['tool_call_id']);
    }

    public function test_duplicate_ids_in_one_provider_batch_receive_distinct_results_and_execute_in_order(): void
    {
        $conversation = AgentConversation::create(['phase' => 1, 'messages' => []]);
        $client = new FakeChatClient([
            new ChatResponse(null, [
                ['id' => 'duplicate', 'name' => 'set_phase', 'arguments' => ['phase' => 2]],
                ['id' => 'duplicate', 'name' => 'set_phase', 'arguments' => ['phase' => 6]],
            ], 'fake'),
            new ChatResponse('Both changes saved.', [], 'fake'),
        ]);

        $result = (new StrategyAgent($client, new ToolRegistry([new SetPhaseTool])))->turn($conversation->id, 'Advance through two phases.');

        $this->assertNull($result['error']);
        $this->assertSame([2, 6], array_column(array_column($result['tool_events'], 'args'), 'phase'));
        $this->assertSame(6, $conversation->fresh()->phase);
        $messages = $conversation->fresh()->messages;
        $ids = array_column($messages[1]['tool_calls'], 'id');
        $this->assertSame('duplicate', $ids[0]);
        $this->assertNotSame($ids[0], $ids[1]);
        $this->assertSame($ids, array_column([$messages[2], $messages[3]], 'tool_call_id'));
        $this->assertSame($ids, array_column($client->calls[1]['messages'][2]['tool_calls'], 'id'));
    }

    public function test_graph_save_then_backtest_uses_the_plugin_and_version_saved_in_the_same_turn(): void
    {
        Bus::fake([RunBacktest::class]);
        $definition = json_decode(file_get_contents(resource_path('strategies/examples/mean-reversion.json')), true);
        $conversation = AgentConversation::create(['messages' => [], 'phase' => 7]);
        $client = new FakeChatClient([
            new ChatResponse(null, [['id' => 'save', 'name' => 'strategy_json', 'arguments' => ['definition' => $definition]]], 'fake'),
            new ChatResponse(null, [['id' => 'test', 'name' => 'run_backtest', 'arguments' => ['products' => ['BTC-USD'], 'days' => 1]]], 'fake'),
            new ChatResponse('The saved version has been submitted for testing.', [], 'fake'),
        ]);

        $result = (new StrategyAgent($client, new ToolRegistry([new StrategyJsonTool, new RunBacktestTool])))->turn($conversation->id, 'save and test');

        $this->assertNull($result['error']);
        $this->assertTrue($result['tool_events'][0]['result']['valid']);
        $plugin = StrategyPlugin::findOrFail($conversation->fresh()->plugin_id);
        $backtest = Backtest::findOrFail($result['tool_events'][1]['result']['id']);
        $version = $plugin->versions()->sole();
        $this->assertSame($version->id, $backtest->strategy_plugin_version_id);
        $this->assertSame($version->id, $backtest->params['json.plugin_version_id']);
        $this->assertSame($plugin->key, $backtest->params['json.plugin_key']);
        Bus::assertDispatchedSync(RunBacktest::class);
    }

    public function test_graph_tool_node_executes_registered_application_tools_using_the_application_transport(): void
    {
        $tool = new class implements Tool
        {
            public bool $ranInGraph = false;

            public function name(): string
            {
                return 'graph_probe';
            }

            public function description(): string
            {
                return 'Records a phase for the graph integration test.';
            }

            public function parameters(): array
            {
                return ['type' => 'object'];
            }

            public function run(array $args, AgentContext $context): array
            {
                $classes = array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'class');
                $this->ranInGraph = in_array(ToolNode::class, $classes, true);
                $context->phase = 4;

                return ['phase' => $context->phase];
            }
        };
        $client = new FakeChatClient([
            new ChatResponse(null, [['id' => 'probe', 'name' => 'graph_probe', 'arguments' => []]], 'fake'),
            new ChatResponse('Phase four recorded.', [], 'fake'),
        ]);
        $conversation = AgentConversation::create(['messages' => [], 'phase' => 1]);
        Http::preventStrayRequests();

        $result = (new StrategyAgent($client, new ToolRegistry([$tool])))->turn($conversation->id, 'record phase');

        $this->assertNull($result['error']);
        $this->assertTrue($tool->ranInGraph, 'The registered tool must execute inside the SDK ToolNode.');
        $this->assertSame(4, $conversation->fresh()->phase);
        $this->assertSame('Phase four recorded.', $result['content']);
        $this->assertCount(2, $client->calls);
        $this->assertSame('graph_probe', $client->calls[0]['tools'][0]['function']['name']);
        $this->assertSame('probe', $client->calls[1]['messages'][3]['tool_call_id']);
        Http::assertNothingSent();
    }

    public function test_a_provider_failure_after_a_graph_tool_keeps_completed_state_and_tool_results(): void
    {
        $client = new class implements ChatClient
        {
            private int $calls = 0;

            public function defaultModel(): string
            {
                return 'fake';
            }

            public function chat(array $messages, array $tools = [], ?string $model = null, array $options = []): ChatResponse
            {
                if ($this->calls++ === 0) {
                    return new ChatResponse(null, [['id' => 'saved', 'name' => 'set_phase', 'arguments' => ['phase' => 5]]], 'fake');
                }

                throw new \RuntimeException('provider unavailable');
            }
        };
        $conversation = AgentConversation::create(['messages' => [], 'phase' => 1]);

        $result = (new StrategyAgent($client, new ToolRegistry([new SetPhaseTool])))->turn($conversation->id, 'continue');

        $this->assertSame('provider unavailable', $result['error']);
        $this->assertSame(5, $conversation->fresh()->phase);
        $this->assertCount(1, $result['tool_events']);
        $messages = $conversation->fresh()->messages;
        $this->assertCount(3, $messages);
        $this->assertSame('saved', $messages[2]['tool_call_id']);
        $this->assertSame(5, json_decode($messages[2]['content'], true)['phase']);
    }

    public function test_one_blank_pre_tool_response_is_retried_without_persisting_a_dead_reply(): void
    {
        $conversation = AgentConversation::create(['messages' => [], 'phase' => 1]);
        $client = new FakeChatClient([
            new ChatResponse(null, [], 'fake'),
            new ChatResponse('What market condition should trigger the setup?', [], 'fake'),
        ]);

        $result = (new StrategyAgent($client, new ToolRegistry))->turn($conversation->id, 'build a strategy');

        $this->assertNull($result['error']);
        $this->assertCount(2, $client->calls);
        $this->assertSame($client->calls[0]['messages'], $client->calls[1]['messages']);
        $this->assertCount(2, $conversation->fresh()->messages);
        $this->assertSame('What market condition should trigger the setup?', $result['content']);
    }

    public function test_obsolete_hub_credentials_are_scrubbed_before_graph_state_events_and_persistence(): void
    {
        $conversation = AgentConversation::create(['messages' => [], 'phase' => 1]);
        $client = new FakeChatClient([
            new ChatResponse(null, [['id' => 'hub', 'name' => 'hub_register', 'arguments' => [
                'email' => 'private@example.test', 'handle' => 'private-handle', 'password' => 'obsolete-secret',
            ]]], 'fake'),
            new ChatResponse('Finish registration in the Hub panel.', [], 'fake'),
        ]);
        Http::preventStrayRequests();

        $result = (new StrategyAgent($client, new ToolRegistry([new HubRegisterTool])))->turn($conversation->id, 'connect the hub');

        $this->assertNull($result['error']);
        $this->assertSame([], $result['tool_events'][0]['args']);
        $this->assertArrayHasKey('needs_confirmation', $result['tool_events'][0]['result'], json_encode($result['tool_events']));
        $this->assertTrue($result['tool_events'][0]['result']['needs_confirmation']);
        $transcripts = json_encode([$conversation->fresh()->messages, $result, $client->calls]);
        foreach (['obsolete-secret', 'private@example.test', 'private-handle'] as $secret) {
            $this->assertStringNotContainsString($secret, $transcripts);
        }
        $this->assertSame('{}', $conversation->fresh()->messages[1]['tool_calls'][0]['function']['arguments']);
        $this->assertDatabaseCount('hub_connections', 0);
        Http::assertNothingSent();
    }
}
