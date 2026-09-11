<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Desk;
use App\Exchange\Coinbase\CoinbaseMarketData;
use App\Exchange\Contracts\MarketData;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fixtures\AddOnEveryScanStrategy;
use Tests\TestCase;

/**
 * A strategy's add-restraint knobs (add_min_bars_between,
 * add_requires_new_signal, add_max_pct_of_initial) stamp last_action_ts / last_signal_ts /
 * initial_cost_usd onto the strategy's own in-memory Position copy during scan()/size() — but Desk
 * rebuilds positions fresh from the DB per candidate and never saves that copy again, so the stamp
 * never reached disk and live never honoured it (only backtests, which keep positions in memory
 * across bars, did). Desk::doEnter() now persists these itself, where the fill that actually happened
 * is recorded: initial_cost_usd is set once, on the position's first entry, from that fill's own
 * notional, and never touched again; last_action_ts/last_signal_ts update on every entry AND add.
 */
class DeskAddRestraintMetaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);

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

        app('App\Desk\Settings')->set('paper.slippage_bps', 0);
        app('App\Desk\Settings')->set('fees.taker_rate', 0.0);
        app('App\Desk\Settings')->set('fees.per_contract_usd', 0);
    }

    public function test_meta_carries_the_first_fills_cost_and_the_latest_action_and_signal_timestamps(): void
    {
        config(['desk.strategies.add_on_every_scan_test' => AddOnEveryScanStrategy::class, 'desk.strategy' => 'add_on_every_scan_test']);
        $this->app->instance(AddOnEveryScanStrategy::class, new AddOnEveryScanStrategy(100.0, 'BTC-USD', [1000, 2000]));

        $desk = app(Desk::class);
        $before = now()->getTimestamp();

        $run1 = $desk->cycle();
        $this->assertSame('done', $run1->status);
        $this->assertSame(1, $run1->filled, 'the first cycle must enter');

        $position = Position::sole();
        $this->assertSame(0, $position->adds_count);
        $firstEntryUsd = (float) $position->entry_usd;
        $this->assertEqualsWithDelta($firstEntryUsd, $position->meta['initial_cost_usd'], 1e-9, 'initial_cost_usd is the true cost of the fill that opened the position');
        $this->assertGreaterThanOrEqual($before, $position->meta['last_action_ts']);
        $this->assertSame(1000, $position->meta['last_signal_ts'], 'the first call\'s signal timestamp');

        $run2 = $desk->cycle();
        $this->assertSame('done', $run2->status);
        $this->assertSame(1, $run2->filled, 'the second cycle must add to the same position');

        $position->refresh();
        $this->assertSame(1, $position->adds_count);
        $this->assertEqualsWithDelta($firstEntryUsd, $position->meta['initial_cost_usd'], 1e-9, 'an add must never recompute or overwrite initial_cost_usd');
        $this->assertGreaterThanOrEqual($before, $position->meta['last_action_ts'], 'last_action_ts must advance on the add too');
        $this->assertSame(2000, $position->meta['last_signal_ts'], 'an add must overwrite last_signal_ts with the NEW call\'s signal, not keep the entry\'s');
    }
}
