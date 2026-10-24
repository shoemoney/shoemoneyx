<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\AgentContext;
use App\Ai\ChatResponse;
use App\Ai\Contracts\ChatClient;
use App\Ai\StrategyAgent;
use App\Ai\Tools\CompareVersionsTool;
use App\Ai\Tools\SetPhaseTool;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolRegistry;
use App\Desk\Backtester;
use App\Desk\Strategies\JsonPluginValidator;
use App\Models\AgentConversation;
use App\Models\Candle;
use App\Models\Product;
use App\Models\StrategyPlugin;
use App\Models\StrategyPluginVersion;
use Carbon\Carbon;
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

    /**
     * Phase D's proof (docs/STRATEGY_SCHEMA_V2.md, "Build order"): paste -> validate -> backtest,
     * for a real schema_version:2 payload — the only strategy_json tool test before this one fed
     * a legacy flat-shape definition, so SchemaMigrator::validateForSave()'s v2 branch, and the
     * saved-then-run path the phase is about, went untested on the v2 branch.
     */
    public function test_strategy_json_tool_call_saves_and_backtests_a_v2_definition(): void
    {
        config(['cache.default' => 'array']);
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);

        $conversation = AgentConversation::create(['messages' => [], 'phase' => 1]);
        $def = json_decode(file_get_contents(base_path('resources/strategies/examples/smx-pi-take-profit-v2.json')), true);

        $fake = new FakeChatClient([
            new ChatResponse(
                content: null,
                toolCalls: [['id' => 'call_1', 'name' => 'strategy_json', 'arguments' => ['definition' => $def]]],
                model: 'fake',
            ),
            new ChatResponse(content: 'Saved as v1.0.0.', toolCalls: [], model: 'fake'),
        ]);
        $agent = $this->makeAgent($fake);

        $result = $agent->turn($conversation->id, 'here is my v2 strategy');

        $this->assertSame('Saved as v1.0.0.', $result['content']);
        $plugin = StrategyPlugin::where('key', $def['key'])->first();
        $this->assertNotNull($plugin, 'the v2 definition must have saved a plugin');
        $this->assertSame(2, $plugin->definition['schema_version']);
        $this->assertSame('1.0.0', $plugin->current_version);
        $this->assertSame(
            1,
            StrategyPluginVersion::where('strategy_plugin_id', $plugin->id)->where('version', '1.0.0')->count(),
        );

        // ...and the saved plugin actually runs the v2 ladder end to end: a 30-bar warmup, a +2.5%
        // high-volume jump SCAN reads as its candidate, then a run-up through all four rungs to a
        // peak (+10%) and a 1%+ giveback that fires the runner — same tape shape as
        // JsonRunnerV2LadderReentryBacktestTest, trimmed to just this one leg of it.
        $from = Carbon::parse('2024-01-01 00:00:00', 'UTC');
        $ts = $from->copy();
        $price = 100.0;
        for ($i = 0; $i < 30; $i++) {
            $ts->addHour();
            $open = $price;
            $price *= 1 + ($i % 2 === 0 ? 0.003 : -0.003);
            Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $ts->copy(), 'open' => $open, 'high' => max($open, $price), 'low' => min($open, $price), 'close' => $price, 'volume' => 230]);
        }
        $ts->addHour();
        $open = $price;
        $jump = $price * 1.025;
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $ts->copy(), 'open' => $open, 'high' => max($open, $jump), 'low' => min($open, $jump), 'close' => $jump, 'volume' => 900]);
        $fill = $jump * 0.995;
        $ts->addHour();
        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $ts->copy(), 'open' => $fill, 'high' => $fill, 'low' => $fill, 'close' => $fill, 'volume' => 230]);
        $prev = $fill;
        foreach ([1.016, 1.030, 1.045, 1.060, 1.075, 1.09, 1.10, 1.08] as $r) {
            $close = $fill * $r;
            $ts->addHour();
            Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '1H', 'candle_start' => $ts->copy(), 'open' => $prev, 'high' => max($prev, $close), 'low' => min($prev, $close), 'close' => $close, 'volume' => 230]);
            $prev = $close;
        }
        $to = $ts->copy()->addHour();

        $bt = app(Backtester::class)->run('json', ['BTC-USD'], $from, $to, 2_000_000.0, [
            'json.plugin_key' => $def['key'],
            'fees.taker_rate' => 0.0, 'fees.maker_rate' => 0.0, 'fees.funding_hourly_pct' => 0.0,
            'fees.per_contract_usd' => 0.0, 'fees.contract_usd' => 0.0,
            'paper.slippage_bps' => 0.0,
            'vet.max_pct_of_volume_24h' => 100.0,
        ]);

        $this->assertSame('done', $bt->status);
        $this->assertSame(4, $bt->trades[0]['trims'], 'all four take_profit rungs fired');
        $this->assertSame('take_profit.runner.ttp', $bt->trades[0]['rule'], 'the runner closes the remainder after the giveback');
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
        // The seventh request asks for a conclusion with no tools. Even if the
        // provider ignores that, its requested calls receive explicit skipped results.
        $this->assertCount(7, $fake->calls);
        $this->assertSame([], $fake->calls[6]['tools']);
        $this->assertSame(7, $conversation->fresh()->phase);
        $this->assertCompleteToolBatches($conversation->fresh()->messages);
        $skipped = array_values(array_filter($conversation->fresh()->messages, fn ($m) => ($m['tool_call_id'] ?? null) === 'call_6'));
        $this->assertStringContainsString('skipped', $skipped[0]['content']);
    }

    public function test_oversized_tool_batch_is_fully_answered_before_a_text_only_conclusion(): void
    {
        $conversation = AgentConversation::create(['messages' => [], 'phase' => 1]);
        $calls = array_map(fn ($i) => ['id' => "call_{$i}", 'name' => 'set_phase', 'arguments' => ['phase' => $i % 7 + 1]], range(0, 7));
        $fake = new FakeChatClient([
            new ChatResponse(content: null, toolCalls: $calls, model: 'fake'),
            new ChatResponse(content: 'Six steps completed; the remaining steps need another turn.', toolCalls: [], model: 'fake'),
        ]);

        $result = $this->makeAgent($fake)->turn($conversation->id, 'go');

        $this->assertCount(6, $result['tool_events']);
        $this->assertCount(2, $fake->calls);
        $this->assertSame([], $fake->calls[1]['tools']);
        $this->assertCompleteToolBatches($fake->calls[1]['messages']);
        $this->assertCompleteToolBatches($conversation->fresh()->messages);
        $this->assertSame('Six steps completed; the remaining steps need another turn.', $result['content']);
        $toolMessages = array_values(array_filter($conversation->fresh()->messages, fn ($m) => $m['role'] === 'tool'));
        $this->assertCount(8, $toolMessages);
        $this->assertStringContainsString('skipped', $toolMessages[6]['content']);
        $this->assertStringContainsString('skipped', $toolMessages[7]['content']);
    }

    public function test_tool_exception_preserves_completed_changes_and_answers_the_rest_of_the_batch(): void
    {
        $conversation = AgentConversation::create(['messages' => [], 'phase' => 1]);
        $throwingTool = new class implements Tool
        {
            public function name(): string
            {
                return 'mcp.test.failing';
            }

            public function description(): string
            {
                return 'Fails for a transport error.';
            }

            public function parameters(): array
            {
                return ['type' => 'object'];
            }

            public function run(array $args, AgentContext $context): array
            {
                throw new \RuntimeException('server disconnected');
            }
        };
        $fake = new FakeChatClient([
            new ChatResponse(content: null, toolCalls: [
                ['id' => 'before', 'name' => 'set_phase', 'arguments' => ['phase' => 3]],
                ['id' => 'failed', 'name' => 'mcp.test.failing', 'arguments' => []],
                ['id' => 'after', 'name' => 'set_phase', 'arguments' => ['phase' => 4]],
            ], model: 'fake'),
            new ChatResponse(content: 'The MCP call failed, but phase changes were saved.', toolCalls: [], model: 'fake'),
        ]);
        $agent = new StrategyAgent($fake, new ToolRegistry([new SetPhaseTool, $throwingTool]));

        $result = $agent->turn($conversation->id, 'continue');

        $this->assertNull($result['error']);
        $this->assertCount(3, $result['tool_events']);
        $this->assertSame(4, $conversation->fresh()->phase);
        $this->assertStringContainsString('server disconnected', $result['tool_events'][1]['result']['error']);
        $this->assertCompleteToolBatches($fake->calls[1]['messages']);
        $messages = $conversation->fresh()->messages;
        $this->assertStringStartsWith('<untrusted_data>', $messages[3]['content']);
        $this->assertCompleteToolBatches($messages);
    }

    public function test_history_drops_an_entire_old_turn_when_the_boundary_splits_a_tool_batch(): void
    {
        $seeded = [
            ['role' => 'user', 'content' => 'old turn'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'old_1', 'type' => 'function', 'function' => ['name' => 'set_phase', 'arguments' => '{"phase":2}']],
                ['id' => 'old_2', 'type' => 'function', 'function' => ['name' => 'set_phase', 'arguments' => '{"phase":3}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'old_1', 'content' => '{"phase":2}'],
            ['role' => 'tool', 'tool_call_id' => 'old_2', 'content' => '{"phase":3}'],
            ['role' => 'assistant', 'content' => 'old reply'],
        ];
        for ($i = 1; $i <= 18; $i++) {
            $seeded[] = ['role' => 'user', 'content' => "turn {$i}"];
            $seeded[] = ['role' => 'assistant', 'content' => "reply {$i}"];
        }
        $conversation = AgentConversation::create(['messages' => $seeded, 'phase' => 3]);
        $fake = new FakeChatClient([new ChatResponse(content: 'new reply', toolCalls: [], model: 'fake')]);

        $this->makeAgent($fake)->turn($conversation->id, 'new turn');

        $messages = $conversation->fresh()->messages;
        $this->assertCount(38, $messages);
        $this->assertSame('turn 1', $messages[0]['content']);
        $this->assertCompleteToolBatches($fake->calls[0]['messages']);
        $this->assertCompleteToolBatches($messages);
    }

    private function assertCompleteToolBatches(array $messages): void
    {
        $pending = [];
        foreach ($messages as $message) {
            if ($message['role'] === 'tool') {
                $this->assertContains($message['tool_call_id'], $pending, 'a tool response must answer a preceding assistant call');
                $pending = array_values(array_diff($pending, [$message['tool_call_id']]));
            } else {
                $this->assertSame([], $pending, 'every assistant tool call must be answered before the next non-tool message');
                $pending = array_column($message['tool_calls'] ?? [], 'id');
            }
        }
        $this->assertSame([], $pending, 'the transcript must not end with unanswered tool calls');
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
