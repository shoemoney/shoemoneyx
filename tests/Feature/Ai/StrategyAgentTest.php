<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\AgentContext;
use App\Ai\ChatResponse;
use App\Ai\Contracts\ChatClient;
use App\Ai\StrategyAgent;
use App\Ai\Tools\CompareVersionsTool;
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

        // ...and the saved plugin actually runs: a 30-bar warmup, a +2.5% high-volume jump SCAN
        // reads as its candidate, then a clean run-up past every rung and the runner's giveback —
        // same tape shape as JsonRunnerV2LadderReentryBacktestTest, trimmed to just prove a trade.
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
        foreach ([1.016, 1.030, 1.045, 1.060, 1.075, 1.09] as $r) {
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
        $this->assertGreaterThanOrEqual(1, count($bt->trades), 'the saved v2 plugin must have produced at least one trade');
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
