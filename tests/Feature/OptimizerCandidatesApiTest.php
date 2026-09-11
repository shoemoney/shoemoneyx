<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Backtest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OptimizerCandidatesApiTest extends TestCase
{
    use RefreshDatabase;

    private function row(string $coin, string $side, string $win, int $cand, ?string $batch, float $ret, array $extra = [], ?string $tag = null): void
    {
        $params = ['_opt' => ['coin' => $coin, 'window' => $win, 'cand' => $cand, 'side' => $side, 'batch' => $batch, 'tag' => $tag]]
            + ($side === 'short' ? ['mr.short.timeframe' => '4m', 'mr.short.short_stop_pct' => 3, 'mr.leverage' => 2] : ['mr.timeframe' => '2m', 'mr.engine.base_minutes' => 720, 'mr.leverage' => 3])
            + $extra;
        Backtest::create([
            'strategy' => 'mr', 'products' => [$coin], 'from' => now()->subDays(8), 'to' => now()->subDay(), 'starting_cash' => 10000,
            'status' => 'done', 'params' => $params, 'stats' => ['total_return_pct' => $ret, 'trades' => 40, 'profit_factor' => 1.8, 'max_drawdown_pct' => 2.2],
        ]);
    }

    public function test_pairs_train_and_test_by_batch_and_reads_the_side_namespace(): void
    {
        $this->row('BTC-USD', 'long', 'train', 0, 'b1', 5.4);
        $this->row('BTC-USD', 'long', 'test', 0, 'b1', 2.1);
        $this->row('BTC-USD', 'long', 'train', 1, 'b1', 3.0);          // unpaired: no test row
        $this->row('BTC-USD', 'short', 'train', 0, 'b2', 1.5);
        $this->row('BTC-USD', 'short', 'test', 0, 'b2', 0.7);
        $this->row('ETH-USD', 'long', 'train', 0, 'b3', 9.9);
        $this->row('ETH-USD', 'long', 'test', 0, 'b3', 1.0);

        $long = $this->getJson('/api/optimizer/candidates?coin=btc-usd&side=long')->assertOk()->json();
        $this->assertSame('BTC-USD', $long['coin']);
        $this->assertCount(1, $long['points'], 'only paired candidates, only this coin and side');
        $this->assertSame(5.4, $long['points'][0]['train']);
        $this->assertSame(2.1, $long['points'][0]['test']);
        $this->assertSame('2m', $long['points'][0]['tf']);
        $this->assertSame(720, $long['points'][0]['base']);
        $this->assertSame(40, $long['points'][0]['trades']);

        $short = $this->getJson('/api/optimizer/candidates?coin=BTC-USD&side=short')->assertOk()->json();
        $this->assertCount(1, $short['points']);
        $this->assertSame('4m', $short['points'][0]['tf'], 'short side reads mr.short.*');
        $this->assertSame(3, $short['points'][0]['stop']);
        $this->assertSame(2, $short['points'][0]['lev'], 'falls back to mr.* for unset short keys');
    }

    public function test_coin_is_required(): void
    {
        $this->getJson('/api/optimizer/candidates')->assertStatus(422);
    }

    public function test_filters_by_tag(): void
    {
        $this->row('BTC-USD', 'long', 'train', 0, 'b1', 5.4, tag: 'squeeze-v2');
        $this->row('BTC-USD', 'long', 'test', 0, 'b1', 2.1, tag: 'squeeze-v2');
        $this->row('BTC-USD', 'long', 'train', 1, 'b2', 3.0);
        $this->row('BTC-USD', 'long', 'test', 1, 'b2', 1.0);

        $tagged = $this->getJson('/api/optimizer/candidates?coin=BTC-USD&side=long&tag=squeeze-v2')->assertOk()->json();
        $this->assertCount(1, $tagged['points']);
        $this->assertSame(5.4, $tagged['points'][0]['train']);

        $untagged = $this->getJson('/api/optimizer/candidates?coin=BTC-USD&side=long')->assertOk()->json();
        $this->assertCount(2, $untagged['points'], 'no tag filter returns every paired candidate');
    }
}
