<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\DeskContext;
use App\Desk\Settings;
use App\Desk\StrategyRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerCoinParamsTest extends TestCase
{
    use RefreshDatabase;

    public function test_per_product_overrides_layer_on_top_of_globals(): void
    {
        config(['cache.default' => 'array', 'desk.strategy' => 'mr']);
        $this->artisan('desk:coin', ['action' => 'set', 'product' => 'btc-usd', 'kv' => ['mr.timeframe=5m', 'mr.lookback=90', 'mr.entry_z=2.5']])->assertSuccessful();

        $settings = app(Settings::class);
        $strategy = app(StrategyRegistry::class)->make('mr');
        $ctx = new DeskContext($settings->merged($strategy), 'paper');

        $this->assertSame('1m', $ctx->param('mr.timeframe'));
        $this->assertSame(60, $ctx->param('mr.lookback'));
        $this->assertSame($ctx, $ctx->forProduct('ETH-USD'));
        $btc = $ctx->forProduct('BTC-USD');
        $this->assertSame('5m', $btc->param('mr.timeframe'));
        $this->assertSame(90, $btc->param('mr.lookback'));
        $this->assertSame(2.5, $btc->param('mr.entry_z'));
        $this->assertSame($ctx->param('mr.exit_z'), $btc->param('mr.exit_z'));

        $this->artisan('desk:coin', ['action' => 'clear', 'product' => 'BTC-USD'])->assertSuccessful();
        $ctx2 = new DeskContext($settings->merged($strategy), 'paper');
        $this->assertSame($ctx2, $ctx2->forProduct('BTC-USD'));
    }
}
