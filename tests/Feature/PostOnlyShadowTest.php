<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Desk;
use App\Desk\Execution\PaperExecutor;
use App\Desk\Execution\PostOnlyShadows;
use App\Desk\Settings;
use App\Exchange\Coinbase\CoinbaseMarketData;
use App\Exchange\Contracts\MarketData;
use App\Models\Candle;
use App\Models\Fill;
use App\Models\PaperLedger;
use App\Models\PostOnlyShadow;
use App\Models\Position;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Fixtures\FixedTicketStrategy;
use Tests\TestCase;

class PostOnlyShadowTest extends TestCase
{
    use RefreshDatabase;

    private object $market;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);

        $this->market = new class extends CoinbaseMarketData
        {
            public float $bid = 99.5;

            public float $ask = 100.5;

            public function ticker(string $productId, int $limit = 100): array
            {
                return ['best_bid' => $this->bid, 'best_ask' => $this->ask, 'trades' => []];
            }

            public function healthy(): bool
            {
                return true;
            }
        };
        $this->app->instance(MarketData::class, $this->market);

        app(Settings::class)->set('paper.slippage_bps', 0);
        app(Settings::class)->set('fees.taker_rate', 0.0);
        app(Settings::class)->set('fees.maker_rate', 0.004);
        app(Settings::class)->set('fees.per_contract_usd', 0);
        app(Settings::class)->set('post_only.ttl_seconds', 300);
        app(Settings::class)->set('post_only.chase_pct', 0.05);
        app(Settings::class)->set('post_only.bar_timeframe', '13s');
        app(Settings::class)->set('post_only.shadow', true);
    }

    private function seedShadow(array $overrides = []): PostOnlyShadow
    {
        $fill = Fill::create(array_merge([
            'mode' => 'paper', 'product_id' => 'BTC-USD', 'side' => 'BUY', 'kind' => 'entry',
            'requested_usd' => 100.0, 'filled_usd' => 100.0, 'filled_qty' => 1.0,
            'decision_price' => 100.0, 'fill_price' => 101.0, 'fee_usd' => 0.1, 'status' => 'filled',
        ], $overrides['fill'] ?? []));

        $placedAt = $overrides['placed_at'] ?? now();

        return PostOnlyShadow::create(array_merge([
            'fill_id' => $fill->id,
            'mode' => 'paper',
            'product_id' => 'BTC-USD',
            'side' => 'BUY',
            'kind' => 'entry',
            'qty' => 1.0,
            'actual_price' => 101.0,
            'actual_fee_usd' => 0.1,
            'limit_price' => 100.0,
            'status' => 'resting',
            'chase_count' => 0,
            'placed_at' => $placedAt,
            'expires_at' => (clone $placedAt)->addSeconds(300),
            'meta' => [],
        ], array_diff_key($overrides, ['fill' => null, 'placed_at' => null])));
    }

    public function test_paper_entry_creates_a_resting_shadow_at_best_bid_and_none_when_the_setting_is_off(): void
    {
        config(['desk.strategies.fixed_ticket_test' => FixedTicketStrategy::class, 'desk.strategy' => 'fixed_ticket_test']);
        app(Settings::class)->set('post_only.shadow', false);
        $this->app->instance(FixedTicketStrategy::class, new FixedTicketStrategy(100.0, 'BTC-USD', 'long'));
        $desk = app(Desk::class);

        $run = $desk->cycle();
        $this->assertSame(1, $run->filled);
        $this->assertSame(0, PostOnlyShadow::count(), 'no shadow while post_only.shadow is off');

        app(Settings::class)->set('post_only.shadow', true);
        $this->app->instance(FixedTicketStrategy::class, new FixedTicketStrategy(100.0, 'ETH-USD', 'long'));
        $run2 = $desk->cycle();
        $this->assertSame(1, $run2->filled);

        $fill = Fill::where('product_id', 'ETH-USD')->sole();
        $shadow = PostOnlyShadow::sole();
        $this->assertSame($fill->id, $shadow->fill_id);
        $this->assertSame('resting', $shadow->status);
        $this->assertSame('entry', $shadow->kind);
        $this->assertSame('BUY', $shadow->side);
        $this->assertEqualsWithDelta(99.5, $shadow->limit_price, 1e-9, 'a BUY entry shadow rests at best_bid');
        $this->assertEqualsWithDelta($fill->filled_qty, $shadow->qty, 1e-9);
        $this->assertEqualsWithDelta($fill->fill_price, $shadow->actual_price, 1e-9);
        $this->assertEqualsWithDelta($fill->fee_usd, $shadow->actual_fee_usd, 1e-9);
    }

    public function test_resolve_fills_a_buy_shadow_when_a_bar_trades_through_the_limit(): void
    {
        $placedAt = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($placedAt->copy()->addMinutes(1));
        $shadow = $this->seedShadow(['placed_at' => $placedAt]);

        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '13s', 'candle_start' => $placedAt->copy()->addSeconds(13), 'open' => 101, 'high' => 101, 'low' => 99.0, 'close' => 100, 'volume' => 1]);

        app(PostOnlyShadows::class)->resolve();
        Carbon::setTestNow();

        $shadow->refresh();
        $this->assertSame('filled', $shadow->status);
        $this->assertEqualsWithDelta(100.0, $shadow->shadow_price, 1e-9);
        $this->assertEqualsWithDelta(1.0 * 100.0 * 0.004, $shadow->shadow_fee_usd, 1e-9);
        // saved = (actual - shadow) * qty + (actual_fee - shadow_fee) = (101-100)*1 + (0.1-0.4) = 0.7
        $this->assertEqualsWithDelta(0.7, $shadow->saved_usd, 1e-9);
        $this->assertTrue($placedAt->copy()->addSeconds(13)->equalTo($shadow->resolved_at));
    }

    public function test_resolve_does_not_fill_on_a_bar_whose_low_only_equals_the_limit(): void
    {
        $placedAt = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($placedAt->copy()->addSeconds(30));
        $this->market->bid = 100.0;
        $this->market->ask = 100.0;
        $shadow = $this->seedShadow(['placed_at' => $placedAt]);

        Candle::create(['product_id' => 'BTC-USD', 'timeframe' => '13s', 'candle_start' => $placedAt->copy()->addSeconds(13), 'open' => 101, 'high' => 101, 'low' => 100.0, 'close' => 100.5, 'volume' => 1]);

        app(PostOnlyShadows::class)->resolve();
        Carbon::setTestNow();

        $shadow->refresh();
        $this->assertSame('resting', $shadow->status, 'a bar low that merely equals the limit is a touch, not a fill');
    }

    public function test_resolve_chases_once_when_the_market_runs_away_and_never_chases_twice(): void
    {
        $placedAt = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($placedAt->copy()->addSeconds(30));
        $this->market->bid = 100.10; // > 100 * (1 + 0.05/100) = 100.05
        $this->market->ask = 100.20;
        $shadow = $this->seedShadow(['placed_at' => $placedAt]);

        app(PostOnlyShadows::class)->resolve();
        $shadow->refresh();
        $this->assertSame('resting', $shadow->status);
        $this->assertSame(1, $shadow->chase_count);
        $this->assertEqualsWithDelta(100.10, $shadow->limit_price, 1e-9);
        $this->assertEqualsWithDelta(100.0, $shadow->meta['chase']['from'], 1e-9);
        $this->assertEqualsWithDelta(100.10, $shadow->meta['chase']['to'], 1e-9);

        $this->market->bid = 105.0; // ran away again — must not chase a second time
        app(PostOnlyShadows::class)->resolve();
        Carbon::setTestNow();

        $shadow->refresh();
        $this->assertSame(1, $shadow->chase_count, 'a shadow chases at most once');
        $this->assertEqualsWithDelta(100.10, $shadow->limit_price, 1e-9, 'the limit must not move on a second run-away');
    }

    public function test_resolve_expires_past_ttl_with_miss_move_pct_and_a_second_resolve_is_a_noop(): void
    {
        $placedAt = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($placedAt->copy()->addSeconds(301));
        $this->market->bid = 105.0;
        $this->market->ask = 105.0;
        // Already chased once (chase_count=1): the run-away check never fires again, so a big move
        // here must land on expiry, not a second chase.
        $shadow = $this->seedShadow(['placed_at' => $placedAt, 'expires_at' => $placedAt->copy()->addSeconds(300), 'chase_count' => 1]);

        app(PostOnlyShadows::class)->resolve();
        $shadow->refresh();
        $this->assertSame('expired', $shadow->status);
        $this->assertEqualsWithDelta(5.0, $shadow->miss_move_pct, 1e-9, '(105-100)/100*100 = 5');
        $this->assertEqualsWithDelta(105.0, $shadow->meta['last_price'], 1e-9);
        $resolvedAt = $shadow->resolved_at;

        app(PostOnlyShadows::class)->resolve();
        Carbon::setTestNow();

        $shadow->refresh();
        $this->assertSame('expired', $shadow->status);
        $this->assertTrue($resolvedAt->equalTo($shadow->resolved_at), 'a second resolve() must not touch an already-resolved shadow');
    }

    public function test_desk_trim_with_a_limit_price_creates_a_filled_trim_shadow_at_the_maker_rate(): void
    {
        app(Settings::class)->set('post_only.shadow', true);
        $position = Position::create([
            'mode' => 'paper', 'strategy' => 'test', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 1.0, 'entry_price' => 100.0, 'entry_usd' => 100.0, 'fees_usd' => 0.0,
            'peak_price' => 100.0, 'last_price' => 100.0, 'opened_at' => now(), 'meta' => [],
        ]);
        $this->market->bid = 120.0;
        $this->market->ask = 120.5;
        $ex = app(PaperExecutor::class);
        $desk = app(Desk::class);

        $fill = $desk->trim($position, 0.5, 'take_profit_rung', 120.0, $ex, 120.0);

        $this->assertNotNull($fill);
        $shadow = PostOnlyShadow::sole();
        $this->assertSame($fill->id, $shadow->fill_id);
        $this->assertSame('trim', $shadow->kind);
        $this->assertSame('filled', $shadow->status);
        $this->assertEqualsWithDelta(120.0, $shadow->limit_price, 1e-9);
        $this->assertEqualsWithDelta(120.0, $shadow->shadow_price, 1e-9);
        $this->assertEqualsWithDelta(0.5 * 120.0 * 0.004, $shadow->shadow_fee_usd, 1e-9, 'maker rate, not taker');
        $this->assertNull($shadow->meta['touch_only'], 'no bar seeded — unknown, not a guess');
    }

    public function test_open_short_creates_a_sell_shadow_at_best_ask(): void
    {
        config([
            'desk.perps.enabled' => true,
            'desk.perps.paper_margin' => false,
            'desk.perps.whole_contracts' => false,
            'desk.strategies.fixed_ticket_test' => FixedTicketStrategy::class,
            'desk.strategy' => 'fixed_ticket_test',
            'desk.paper.starting_cash' => 1000,
        ]);
        app(Settings::class)->set('post_only.shadow', true);
        $this->app->instance(FixedTicketStrategy::class, new FixedTicketStrategy(100.0, 'BTC-USD', 'short'));
        $desk = app(Desk::class);

        $run = $desk->cycle();
        $this->assertSame(1, $run->filled);

        $fill = Fill::sole();
        $this->assertSame('SELL', $fill->side);
        $shadow = PostOnlyShadow::sole();
        $this->assertSame('SELL', $shadow->side);
        $this->assertSame('entry', $shadow->kind);
        $this->assertEqualsWithDelta(100.5, $shadow->limit_price, 1e-9, 'a SELL (short open) shadow rests at best_ask');
    }

    public function test_report_command_output_contains_expected_counts_for_a_seeded_mix(): void
    {
        $this->seedShadow(['product_id' => 'BTC-USD', 'kind' => 'entry', 'status' => 'filled', 'saved_usd' => 1.0, 'qty' => 1.0, 'actual_price' => 100.0]);
        $this->seedShadow(['product_id' => 'BTC-USD', 'kind' => 'trim', 'status' => 'expired', 'miss_move_pct' => 2.0]);
        $this->seedShadow(['product_id' => 'ETH-USD', 'kind' => 'entry', 'status' => 'resting']);

        Artisan::call('desk:post-only-report');
        $out = Artisan::output();

        $this->assertStringContainsString('entry', $out);
        $this->assertStringContainsString('trim', $out);
        $this->assertStringContainsString('total', $out);
        $this->assertStringContainsString('BTC-USD', $out);
        $this->assertStringContainsString('ETH-USD', $out);
    }
}
