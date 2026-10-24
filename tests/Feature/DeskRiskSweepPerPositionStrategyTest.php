<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Data\ProductStats;
use App\Desk\Data\RiskDecision;
use App\Desk\Desk;
use App\Desk\DeskContext;
use App\Desk\Strategies\JsonPluginStrategy;
use App\Exchange\Coinbase\CoinbaseMarketData;
use App\Exchange\Contracts\MarketData;
use App\Models\PaperLedger;
use App\Models\Position;
use App\Models\Product;
use App\Models\StrategyPlugin;
use App\Models\StrategyPluginVersion;
use App\Services\Market\CandleStore;
use App\Services\Market\ProductStatsBuilder;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Fixtures\DeskWithFakeLockTimeouts;
use Tests\Feature\Fixtures\HoldRiskStrategy;
use Tests\Feature\Fixtures\HookRiskStrategy;
use Tests\Feature\Fixtures\RiskFixtureStrategy;
use Tests\Feature\Fixtures\StopRiskStrategy;
use Tests\TestCase;

require_once __DIR__.'/Fixtures/RiskFixtureStrategies.php';

/**
 * Review finding T9/D4: the sweep judged every open position by the desk's CURRENT strategy (a
 * strategy switch silently dropped the original stops) and saved the whole meta JSON from a stale
 * copy with no lock (clobbering keys a concurrent bookAdd wrote).
 */
class DeskRiskSweepPerPositionStrategyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'desk.mode' => 'paper',
            'desk.strategies.hold_test' => HoldRiskStrategy::class,
            'desk.strategies.stop_test' => StopRiskStrategy::class,
            'desk.strategies.hook_test' => HookRiskStrategy::class,
        ]);
        RiskFixtureStrategy::reset();

        $this->app->bind(MarketData::class, fn () => new class extends CoinbaseMarketData
        {
            public function ticker(string $productId, int $limit = 100): array
            {
                return ['best_bid' => 100.0, 'best_ask' => 100.0, 'trades' => []];
            }

            public function healthy(): bool
            {
                return true;
            }
        });
        $this->app->bind(ProductStatsBuilder::class, fn () => new class(app(MarketData::class), app(CandleStore::class)) extends ProductStatsBuilder
        {
            public function live(Product $product, bool $withBook = false): ProductStats
            {
                return ProductStats::fromArray(['product_id' => $product->product_id, 'price' => 100.0]);
            }
        });
        app('App\Desk\Settings')->set('paper.slippage_bps', 0);
        app('App\Desk\Settings')->set('fees.taker_rate', 0.0);
        app('App\Desk\Settings')->set('fees.per_contract_usd', 0);

        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);
    }

    private function position(string $strategy, array $extra = []): Position
    {
        return Position::create($extra + [
            'mode' => 'paper', 'strategy' => $strategy, 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 1.0, 'entry_price' => 100.0, 'entry_usd' => 100.0, 'fees_usd' => 0.0,
            'peak_price' => 100.0, 'last_price' => 100.0, 'opened_at' => now()->subHour(), 'meta' => [],
        ]);
    }

    public function test_after_a_strategy_switch_the_old_positions_own_stop_still_fires(): void
    {
        $position = $this->position('stop_test');

        // The desk now runs hold_test, which never stops anything out.
        $desk = app(Desk::class);
        $out = $desk->runRiskSweep(app(HoldRiskStrategy::class), $desk->executorForMode('paper'));

        $this->assertSame('CLOSE', $out[0]['action']);
        $this->assertSame('stop_test_stop', $out[0]['rule']);
        $this->assertSame('closed', $position->fresh()->status);
    }

    public function test_a_position_opened_under_the_active_strategy_is_judged_by_it(): void
    {
        $this->position('hold_test');

        $desk = app(Desk::class);
        $out = $desk->runRiskSweep(app(HoldRiskStrategy::class), $desk->executorForMode('paper'));

        $this->assertSame('HOLD', $out[0]['action']);
    }

    public function test_a_position_with_an_unregistered_strategy_falls_back_to_the_active_one(): void
    {
        $this->position('long_gone_strategy');

        $desk = app(Desk::class);
        $out = $desk->runRiskSweep(app(HoldRiskStrategy::class), $desk->executorForMode('paper'));

        $this->assertSame('HOLD', $out[0]['action']);
    }

    public function test_the_pinned_plugin_version_reaches_the_strategy_context(): void
    {
        $this->position('hook_test', ['plugin_version_id' => 4242]);

        $desk = app(Desk::class);
        $desk->runRiskSweep(app(HookRiskStrategy::class), $desk->executorForMode('paper'));

        $this->assertSame(4242, RiskFixtureStrategy::$lastCtx?->param('json.plugin_version_id'));
    }

    public function test_the_sweep_save_keeps_meta_keys_written_concurrently(): void
    {
        $position = $this->position('hook_test', ['meta' => ['entry_fees_usd' => 1.0]]);

        // Mid-risk(), a concurrent bookAdd lands: it adds keys the sweep's in-memory copy has never seen.
        RiskFixtureStrategy::$onRisk = function (Position $p, DeskContext $ctx): RiskDecision {
            Position::whereKey($p->id)->first()->update(['meta' => ['entry_fees_usd' => 1.0, 'entry_fee_excluded' => 0.5, 'margin_usd' => 25.0, 'last_action_ts' => 123]]);
            $p->meta = array_merge($p->meta ?? [], ['sweep_note' => 'mine']);

            return RiskDecision::hold();
        };

        $desk = app(Desk::class);
        $desk->runRiskSweep(app(HookRiskStrategy::class), $desk->executorForMode('paper'));

        $meta = $position->fresh()->meta;
        $this->assertSame('mine', $meta['sweep_note'], 'the key the sweep changed is saved');
        $this->assertEquals(25.0, $meta['margin_usd'], 'a key a concurrent add wrote must survive the sweep save');
        $this->assertSame(0.5, $meta['entry_fee_excluded']);
        $this->assertSame(123, $meta['last_action_ts']);
    }

    public function test_accrue_funding_keeps_meta_keys_written_concurrently(): void
    {
        $position = $this->position('hook_test', ['opened_at' => now()->subHours(3)]);
        $stale = Position::find($position->id);

        Position::whereKey($position->id)->first()->update(['meta' => ['margin_usd' => 25.0, 'last_action_ts' => 123]]);

        app(Desk::class)->accrueFunding($stale, 100.0);

        $meta = $position->fresh()->meta;
        $this->assertArrayHasKey('funding_usd', $meta);
        $this->assertEquals(25.0, $meta['margin_usd']);
        $this->assertSame(123, $meta['last_action_ts']);
    }

    public function test_a_lock_timeout_saving_risk_state_skips_the_position_and_acts_next_sweep(): void
    {
        $position = $this->position('hook_test');
        RiskFixtureStrategy::$onRisk = function (Position $p, DeskContext $ctx): RiskDecision {
            $p->meta = array_merge($p->meta ?? [], ['ladder_state' => 7]);

            return RiskDecision::close('hook_stop');
        };

        // Lock call #1 is the sweep's metadata save; it times out.
        $desk = app(DeskWithFakeLockTimeouts::class, ['timeoutOnAttempt' => [1]]);
        $executor = $desk->executorForMode('paper');

        $out = $desk->runRiskSweep(app(HookRiskStrategy::class), $executor);
        $this->assertSame('stale', $out[0]['action'], 'a timed-out save is a skipped position, not an error');
        $position->refresh();
        $this->assertSame('open', $position->status, 'no action may run without its metadata saved');
        $this->assertArrayNotHasKey('ladder_state', $position->meta ?? []);

        $out = $desk->runRiskSweep(app(HookRiskStrategy::class), $executor);
        $this->assertSame('CLOSE', $out[0]['action']);
        $this->assertSame('closed', $position->fresh()->status);
    }

    public function test_funding_is_not_charged_twice_when_another_sweep_already_booked_the_interval(): void
    {
        $position = $this->position('hook_test', ['opened_at' => now()->subHours(3)]);
        $stale = Position::find($position->id);

        Position::find($position->id)->update(['meta' => ['funding_at' => now()->toIso8601String(), 'funding_usd' => 1.0]]);

        app(Desk::class)->accrueFunding($stale, 100.0);

        $this->assertSame(0, PaperLedger::where('kind', 'funding')->count());
    }

    public function test_a_funding_lock_timeout_commits_no_ledger_row(): void
    {
        $position = $this->position('hook_test', ['opened_at' => now()->subHours(3)]);

        $desk = app(DeskWithFakeLockTimeouts::class, ['timeoutOnAttempt' => [1]]);
        try {
            $desk->accrueFunding($position, 100.0);
            $this->fail('expected a lock timeout');
        } catch (LockTimeoutException) {
        }

        $this->assertSame(0, PaperLedger::where('kind', 'funding')->count());
        $this->assertArrayNotHasKey('funding_at', $position->fresh()->meta ?? []);
    }

    public function test_positions_carry_a_nullable_plugin_version_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('positions', 'plugin_version_id'));
        $this->assertNull($this->position('mr')->fresh()->plugin_version_id);
    }

    public function test_pinned_version_id_resolves_an_explicit_pin_and_a_plugin_keys_current_version(): void
    {
        $plugin = StrategyPlugin::create(['key' => 'p8', 'name' => 'p8', 'definition' => ['schema' => 1], 'current_version' => '1.1.0']);
        $v1 = StrategyPluginVersion::create(['strategy_plugin_id' => $plugin->id, 'version' => '1.0.0', 'definition' => ['schema' => 1]]);
        $v2 = StrategyPluginVersion::create(['strategy_plugin_id' => $plugin->id, 'version' => '1.1.0', 'definition' => ['schema' => 1]]);
        $strategy = new JsonPluginStrategy;
        $desk = app(Desk::class);

        $byKey = $desk->context($strategy)->withParams(['json' => ['plugin_key' => 'p8']]);
        $this->assertSame($v2->id, $strategy->pinnedVersionId($byKey));

        $pinned = $desk->context($strategy)->withParams(['json' => ['plugin_version_id' => $v1->id]]);
        $this->assertSame($v1->id, $strategy->pinnedVersionId($pinned));

        $this->assertNull($strategy->pinnedVersionId($desk->context($strategy)));
    }
}
