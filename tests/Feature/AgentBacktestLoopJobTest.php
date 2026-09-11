<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Ai\Contracts\ChatClient;
use App\Ai\Gate;
use App\Desk\Backtester;
use App\Jobs\AgentBacktestLoop;
use App\Models\AiConnection;
use App\Models\Backtest;
use App\Models\StrategyPlugin;
use App\Models\StrategyReview;
use App\Services\Market\CandleStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class AgentBacktestLoopJobTest extends TestCase
{
    use RefreshDatabase;

    private const COMPLETIONS = 'openrouter.ai/api/v1/chat/completions';

    private function plugin(bool $autoBacktest = true): StrategyPlugin
    {
        $definition = [
            'key' => 'rsi-dip',
            'name' => 'RSI Dip',
            'version' => 1,
            'suggest' => ['products' => ['BTC-USD'], 'days' => 10, 'cash' => 500],
            'scan' => ['filters' => [['field' => 'indicators.rsi14', 'op' => '<', 'value' => 35]]],
        ];

        $plugin = StrategyPlugin::create([
            'key' => 'rsi-dip', 'name' => 'RSI Dip', 'definition' => $definition,
            'current_version' => '1.0.0', 'auto_backtest' => $autoBacktest,
        ]);
        $plugin->versions()->create(['version' => '1.0.0', 'definition' => $definition]);

        AiConnection::create([
            'user_id' => 'local', 'provider' => 'openrouter',
            'key' => 'sk-or-v1-connected', 'connected_at' => now(),
        ]);

        return $plugin;
    }

    private function fakeReview(string $body): void
    {
        Http::fake([self::COMPLETIONS => Http::response([
            'model' => 'openrouter/free',
            'choices' => [['message' => ['content' => $body]]],
            'usage' => ['prompt_tokens' => 700, 'completion_tokens' => 40],
        ])]);
    }

    private function runOnce(StrategyPlugin $plugin, Backtester $backtester, CandleStore $store): void
    {
        (new AgentBacktestLoop($plugin->id))->handle($backtester, $store, app(ChatClient::class), app(Gate::class));
    }

    public function test_it_backtests_and_files_a_review_then_skips_the_next_tick(): void
    {
        $plugin = $this->plugin();
        $this->fakeReview(<<<'TXT'
        Here you go.
        ```json
        {"verdict": "abandon", "notes": "Eleven trades, negative expectancy after fees.", "suggested_param_changes": {"scan.max_candidates": 3}}
        ```
        TXT);

        $store = Mockery::mock(CandleStore::class);
        $store->shouldReceive('fresh')->once()->with('BTC-USD', '1H', 300)->andReturn(true);
        $store->shouldNotReceive('sync');

        $backtester = Mockery::mock(Backtester::class);
        $backtester->shouldReceive('runInto')->once()->with(Mockery::on(fn ($row) => $row->strategy === 'json'));

        $this->runOnce($plugin, $backtester, $store);

        $review = StrategyReview::sole();
        $this->assertSame('abandon', $review->verdict);
        $this->assertSame('Eleven trades, negative expectancy after fees.', $review->notes);
        $this->assertSame(['scan.max_candidates' => 3], $review->suggestions);
        $this->assertSame('openrouter/free', $review->model);
        $this->assertSame($plugin->id, $review->plugin_id);

        $backtest = Backtest::sole();
        $this->assertSame($backtest->id, $review->backtest_id);
        $this->assertSame(['BTC-USD'], $backtest->products);
        $this->assertSame(500.0, $backtest->starting_cash);
        $this->assertSame($plugin->versions()->sole()->id, $backtest->strategy_plugin_version_id);

        // Second tick inside the interval: no new backtest, no new review, no Backtester call.
        $idleStore = Mockery::mock(CandleStore::class);
        $idleStore->shouldNotReceive('fresh');
        $idleBacktester = Mockery::mock(Backtester::class);
        $idleBacktester->shouldNotReceive('runInto');

        $this->runOnce($plugin, $idleBacktester, $idleStore);

        $this->assertSame(1, StrategyReview::count());
        $this->assertSame(1, Backtest::count());
    }

    public function test_it_runs_again_once_the_last_backtest_has_aged_out(): void
    {
        $plugin = $this->plugin();
        $this->fakeReview('{"verdict": "keep", "notes": "Holds up.", "suggested_param_changes": {}}');

        $store = Mockery::mock(CandleStore::class);
        $store->shouldReceive('fresh')->andReturn(true);
        $backtester = Mockery::mock(Backtester::class);
        $backtester->shouldReceive('runInto')->twice();

        $this->runOnce($plugin, $backtester, $store);
        Backtest::query()->update(['created_at' => now()->subHours(3)]);
        $this->runOnce($plugin, $backtester, $store);

        $this->assertSame(2, StrategyReview::count());
        $this->assertSame('keep', StrategyReview::orderByDesc('id')->first()->verdict);
    }

    public function test_a_plugin_without_auto_backtest_is_a_no_op(): void
    {
        $plugin = $this->plugin(autoBacktest: false);
        Http::preventStrayRequests();

        $store = Mockery::mock(CandleStore::class);
        $store->shouldNotReceive('fresh');
        $backtester = Mockery::mock(Backtester::class);
        $backtester->shouldNotReceive('runInto');

        $this->runOnce($plugin, $backtester, $store);

        $this->assertSame(0, StrategyReview::count());
        $this->assertSame(0, Backtest::count());
    }

    public function test_an_unparseable_reply_still_files_a_tweak_verdict(): void
    {
        $plugin = $this->plugin();
        $this->fakeReview('I think you should probably keep it, honestly.');

        $store = Mockery::mock(CandleStore::class);
        $store->shouldReceive('fresh')->andReturn(true);
        $backtester = Mockery::mock(Backtester::class);
        $backtester->shouldReceive('runInto')->once();

        $this->runOnce($plugin, $backtester, $store);

        $review = StrategyReview::sole();
        $this->assertSame('tweak', $review->verdict);
        $this->assertSame('', $review->notes);
        $this->assertSame([], $review->suggestions);
    }

    public function test_a_suspended_connection_stops_the_tick_without_a_review(): void
    {
        $plugin = $this->plugin();
        AiConnection::sole()->update(['suspended_until' => now()->addHour()]);
        Http::preventStrayRequests();

        $store = Mockery::mock(CandleStore::class);
        $store->shouldReceive('fresh')->andReturn(true);
        $backtester = Mockery::mock(Backtester::class);
        $backtester->shouldReceive('runInto')->once();

        $this->runOnce($plugin, $backtester, $store);

        $this->assertSame(0, StrategyReview::count());
        $this->assertSame(1, Backtest::count());
    }

    public function test_the_command_visits_only_auto_backtest_strategies(): void
    {
        $this->plugin();
        StrategyPlugin::create([
            'key' => 'manual-only', 'name' => 'Manual Only',
            'definition' => ['key' => 'manual-only'], 'current_version' => '1.0.0', 'auto_backtest' => false,
        ]);
        $this->fakeReview('{"verdict": "keep", "notes": "Fine.", "suggested_param_changes": {}}');

        $backtester = Mockery::mock(Backtester::class);
        $backtester->shouldReceive('runInto')->once();
        $this->app->instance(Backtester::class, $backtester);

        $store = Mockery::mock(CandleStore::class);
        $store->shouldReceive('fresh')->andReturn(true);
        $this->app->instance(CandleStore::class, $store);

        $this->artisan('agent:backtest-loop --once')
            ->expectsOutputToContain('1 auto-backtest strategies visited.')
            ->assertSuccessful();

        $this->assertSame(1, StrategyReview::count());
    }

    public function test_the_toggle_and_reviews_endpoints_drive_the_builder_panel(): void
    {
        $plugin = $this->plugin(autoBacktest: false);

        $this->postJson("/api/strategy-plugins/{$plugin->id}/auto-backtest")
            ->assertOk()->assertExactJson(['auto_backtest' => true]);
        $this->assertTrue($plugin->fresh()->auto_backtest);

        $this->postJson("/api/strategy-plugins/{$plugin->id}/auto-backtest", ['enabled' => false])
            ->assertOk()->assertExactJson(['auto_backtest' => false]);
        $this->assertFalse($plugin->fresh()->auto_backtest);

        $backtest = Backtest::create([
            'strategy' => 'json', 'products' => ['BTC-USD'], 'from' => now()->subDay(),
            'to' => now(), 'starting_cash' => 500, 'status' => 'done', 'params' => [],
        ]);
        StrategyReview::create([
            'plugin_id' => $plugin->id, 'version_id' => $plugin->versions()->sole()->id,
            'backtest_id' => $backtest->id, 'model' => 'openrouter/free',
            'verdict' => 'keep', 'notes' => 'Solid.', 'suggestions' => [], 'created_at' => now(),
        ]);

        $this->getJson("/api/strategy-plugins/{$plugin->id}/reviews")
            ->assertOk()
            ->assertJsonPath('data.0.verdict', 'keep')
            ->assertJsonPath('data.0.notes', 'Solid.');
    }
}
