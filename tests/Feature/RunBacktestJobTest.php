<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Backtester;
use App\Jobs\RunBacktest;
use App\Models\Backtest;
use App\Services\Market\CandleStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RunBacktestJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeBacktest(array $products): Backtest
    {
        return Backtest::create([
            'strategy' => 'mr',
            'products' => $products,
            'from' => now()->subDays(2),
            'to' => now()->subDay(),
            'starting_cash' => 1000,
            'status' => 'queued',
            'params' => [],
        ]);
    }

    public function test_it_skips_sync_for_a_product_whose_1h_candles_are_already_fresh(): void
    {
        $bt = $this->makeBacktest(['BTC-USD']);

        $store = Mockery::mock(CandleStore::class);
        $store->shouldReceive('fresh')->once()->with('BTC-USD', '1H', 300)->andReturn(true);
        $store->shouldNotReceive('sync');

        $backtester = Mockery::mock(Backtester::class);
        $backtester->shouldReceive('runInto')->once()->with(Mockery::on(fn ($row) => $row->id === $bt->id));

        (new RunBacktest($bt->id))->handle($backtester, $store);
    }

    public function test_it_syncs_a_product_whose_1h_candles_are_not_fresh(): void
    {
        $bt = $this->makeBacktest(['ETH-USD']);

        $store = Mockery::mock(CandleStore::class);
        $store->shouldReceive('fresh')->once()->with('ETH-USD', '1H', 300)->andReturn(false);
        $store->shouldReceive('sync')->once()->with('ETH-USD', '1H', Mockery::type('int'));

        $backtester = Mockery::mock(Backtester::class);
        $backtester->shouldReceive('runInto')->once()->with(Mockery::on(fn ($row) => $row->id === $bt->id));

        (new RunBacktest($bt->id))->handle($backtester, $store);
    }
}
