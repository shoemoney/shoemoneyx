<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exchange\Coinbase\Api\CoinbaseService;
use App\Exchange\Coinbase\CoinbaseExecutor;
use App\Models\CoinbaseAccount;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoinbaseExecutorSellQtyTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $increment, string $min): void
    {
        Product::create([
            'product_id' => 'ZZZ-USD', 'base_currency' => 'ZZZ', 'quote_currency' => 'USD',
            'base_increment' => $increment, 'base_min_size' => $min,
        ]);
        CoinbaseAccount::create(['name' => 'test', 'api_key_name' => 'k', 'api_private_key' => 'p', 'is_active' => true]);
    }

    private function filled(float $qty): array
    {
        return ['order' => ['status' => 'FILLED', 'filled_size' => (string) $qty, 'filled_value' => (string) ($qty * 10), 'average_filled_price' => '10', 'total_fees' => '0.01',
            'order_configuration' => ['market_market_ioc' => ['base_size' => (string) $qty]]]];
    }

    public function test_a_quantity_the_venue_can_represent_is_sent_whole(): void
    {
        $this->product('0.01', '0.01');
        $coinbase = \Mockery::mock(CoinbaseService::class);
        $coinbase->shouldReceive('marketSell')->once()
            ->withArgs(fn ($acct, $pid, $qty, $precision) => $qty === 0.29 && $precision === 2)
            ->andReturn(['success' => true, 'success_response' => ['order_id' => 'o-1']]);
        $coinbase->shouldReceive('getOrder')->andReturn($this->filled(0.29));

        $result = (new CoinbaseExecutor($coinbase))->sell('ZZZ-USD', 0.29, 10.0);

        $this->assertTrue($result->ok());
        $this->assertSame(0.0, $result->dustQty);
    }

    public function test_a_sub_increment_tail_is_reported_as_dust_after_a_full_fill(): void
    {
        $this->product('0.01', '0.01');
        $coinbase = \Mockery::mock(CoinbaseService::class);
        $coinbase->shouldReceive('marketSell')->once()
            ->withArgs(fn ($acct, $pid, $qty, $precision) => $qty === 0.12)
            ->andReturn(['success' => true, 'success_response' => ['order_id' => 'o-2']]);
        $coinbase->shouldReceive('getOrder')->andReturn($this->filled(0.12));

        $result = (new CoinbaseExecutor($coinbase))->sell('ZZZ-USD', 0.1234, 10.0);

        $this->assertTrue($result->ok());
        $this->assertEqualsWithDelta(0.0034, $result->dustQty, 1e-12);
    }

    public function test_a_holding_below_the_venue_minimum_sends_nothing_and_is_all_dust(): void
    {
        $this->product('0.01', '0.05');
        $coinbase = \Mockery::mock(CoinbaseService::class);
        $coinbase->shouldNotReceive('marketSell');

        $result = (new CoinbaseExecutor($coinbase))->sell('ZZZ-USD', 0.03, 10.0);

        $this->assertSame('filled', $result->status);
        $this->assertSame(0.0, $result->filledQty);
        $this->assertSame(0.03, $result->dustQty);
        $this->assertFalse($result->ok());
    }
}
