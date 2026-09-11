<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OptimizerRound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OptimizerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_champions_show_each_coins_long_and_short_set_with_latest_round_scores(): void
    {
        config(['cache.default' => 'array', 'desk.strategy' => 'mr', 'desk.perps.active' => ['BTC-USD', 'ETH-USD']]);
        $this->artisan('desk:coin', ['action' => 'set', 'product' => 'btc-usd', 'kv' => ['mr.timeframe=2m', 'mr.leverage=3', 'mr.short.timeframe=3m', 'mr.short.short_stop_pct=2', 'mr.allow_shorts=true']])->assertSuccessful();
        $base = ['strategy' => 'mr', 'train_from' => now()->subDays(30), 'train_to' => now()->subDays(7), 'test_from' => now()->subDays(7), 'test_to' => now(), 'candidates' => 97, 'champion_params' => []];
        OptimizerRound::create($base + ['product_id' => 'BTC-USD', 'side' => 'long', 'champion_train' => 5.38, 'champion_test' => 2.34, 'promoted' => false, 'note' => 'kept']);
        OptimizerRound::create($base + ['product_id' => 'BTC-USD', 'side' => 'short', 'best_train' => 0.87, 'best_test' => 2.66, 'best_params' => ['mr.timeframe' => '3m'], 'promoted' => true, 'note' => 'promoted']);

        $r = $this->getJson('/api/optimizer/champions')->assertOk();
        $btc = collect($r->json('coins'))->firstWhere('coin', 'BTC-USD');
        $eth = collect($r->json('coins'))->firstWhere('coin', 'ETH-USD');

        $this->assertSame('2m', $btc['long']['timeframe']);
        $this->assertSame(3, $btc['long']['leverage']);
        $this->assertSame('3m', $btc['short']['timeframe']);
        $this->assertSame(3, $btc['short']['leverage'], 'short set falls back to the long value for keys it does not set');
        $this->assertTrue($btc['shorts_enabled']);
        $this->assertEquals(5.38, $btc['rounds']['long']['train']);
        $this->assertEquals(2.66, $btc['rounds']['short']['test'], 'a promoted round reports the promoted candidate');
        $this->assertNull($eth['short']);
        $this->assertNull($eth['rounds']['long']);
    }

    public function test_rounds_filter_by_coin_side_and_promotion(): void
    {
        $base = ['strategy' => 'mr', 'train_from' => now()->subDays(30), 'train_to' => now()->subDays(7), 'test_from' => now()->subDays(7), 'test_to' => now(), 'candidates' => 97, 'champion_params' => [], 'note' => ''];
        OptimizerRound::create($base + ['product_id' => 'BTC-USD', 'side' => 'long', 'promoted' => false]);
        OptimizerRound::create($base + ['product_id' => 'BTC-USD', 'side' => 'short', 'promoted' => true]);
        OptimizerRound::create($base + ['product_id' => 'ETH-USD', 'side' => 'short', 'promoted' => false]);

        $this->assertCount(3, $this->getJson('/api/optimizer/rounds')->assertOk()->json());
        $this->assertCount(2, $this->getJson('/api/optimizer/rounds?side=short')->json());
        $this->assertCount(2, $this->getJson('/api/optimizer/rounds?coin=btc-usd')->json());
        $this->assertCount(1, $this->getJson('/api/optimizer/rounds?promoted=1')->json());
        $this->assertSame('ETH-USD', $this->getJson('/api/optimizer/rounds?limit=1')->json()[0]['product_id'], 'newest first');
    }

    public function test_rounds_filter_by_cash(): void
    {
        $base = ['strategy' => 'mr', 'train_from' => now()->subDays(30), 'train_to' => now()->subDays(7), 'test_from' => now()->subDays(7), 'test_to' => now(), 'candidates' => 97, 'champion_params' => [], 'note' => '', 'product_id' => 'BTC-USD', 'side' => 'long', 'promoted' => false];
        OptimizerRound::create($base + ['cash' => 10000]);
        OptimizerRound::create($base + ['cash' => 3000]);

        $rows = $this->getJson('/api/optimizer/rounds?cash=3000')->assertOk()->json();
        $this->assertCount(1, $rows);
        $this->assertEquals(3000, $rows[0]['cash']);
    }

    public function test_rounds_filter_by_tag(): void
    {
        $base = ['strategy' => 'mr', 'train_from' => now()->subDays(30), 'train_to' => now()->subDays(7), 'test_from' => now()->subDays(7), 'test_to' => now(), 'candidates' => 97, 'champion_params' => [], 'note' => '', 'product_id' => 'BTC-USD', 'side' => 'long', 'promoted' => false];
        OptimizerRound::create($base + ['tag' => 'squeeze-v2']);
        OptimizerRound::create($base + ['tag' => null]);

        $rows = $this->getJson('/api/optimizer/rounds?tag=squeeze-v2')->assertOk()->json();
        $this->assertCount(1, $rows);
        $this->assertSame('squeeze-v2', $rows[0]['tag']);
    }
}
