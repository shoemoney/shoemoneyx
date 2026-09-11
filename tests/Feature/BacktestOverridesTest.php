<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Backtester;
use App\Desk\DeskContext;
use App\Desk\StrategyRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: Backtester::simulate() used to merge overrides at the global layer only, so a
 * coin's own per_product.<pid>.* champion settings shadowed --set / sweep-grid / optimizer
 * overrides via forProduct(). Explicit overrides must win for every product in the run.
 */
class BacktestOverridesTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_overrides_beat_per_product_for_simulated_products(): void
    {
        config(['cache.default' => 'array', 'desk.strategy' => 'mr']);
        $this->artisan('desk:coin', ['action' => 'set', 'product' => 'btc-usd', 'kv' => ['mr.timeframe=2m']])->assertSuccessful();
        $this->artisan('desk:coin', ['action' => 'set', 'product' => 'eth-usd', 'kv' => ['mr.timeframe=3m']])->assertSuccessful();

        $strategy = app(StrategyRegistry::class)->make('mr');
        $backtester = app(Backtester::class);
        $overrides = ['mr.timeframe' => '1m', 'mr.engine.x1' => 9];
        $params = $backtester->paramsFor($strategy, ['BTC-USD'], $overrides);

        $ctx = new DeskContext($params, 'paper', backtest: true);

        $btc = $ctx->forProduct('BTC-USD');
        $this->assertSame('1m', $btc->param('mr.timeframe'), 'mr.timeframe was shadowed by the BTC champion');
        $this->assertSame(9, $btc->param('mr.engine.x1'));

        // ETH-USD wasn't in the run's product list, so its own champion is untouched.
        $this->assertSame('3m', $ctx->forProduct('ETH-USD')->param('mr.timeframe'));

        // An explicit per_product override still applies, even for a coin not in the run.
        $params2 = $backtester->paramsFor($strategy, ['BTC-USD'], $overrides + ['per_product.ETH-USD.mr.timeframe' => '5m']);
        $ctx2 = new DeskContext($params2, 'paper', backtest: true);
        $this->assertSame('5m', $ctx2->forProduct('ETH-USD')->param('mr.timeframe'));
    }
}
