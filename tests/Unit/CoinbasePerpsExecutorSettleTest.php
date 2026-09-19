<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exchange\Coinbase\Api\CoinbaseService;
use App\Exchange\Coinbase\CoinbasePerpsExecutor;
use App\Models\CoinbaseAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Round-5 review, BLOCKER 1: OrderResult::ok() got stricter for every executor in round 4, but
 * only CoinbaseExecutor's spot settle() got the decision-price fallback — this venue's settle()
 * still yielded fillPrice 0/null on the exact same degraded poll shape, so OrderResult::ok() was
 * false, Desk::close() left the position open after the venue had already sold it, and the next
 * sweep sold it again. The fallback now lives once, in OrderResult::recoverFillBasis(), and every
 * settle() (including this one) calls it.
 */
class CoinbasePerpsExecutorSettleTest extends TestCase
{
    use RefreshDatabase;

    private function executor(CoinbaseService $coinbase): CoinbasePerpsExecutor
    {
        CoinbaseAccount::create([
            'name' => 'test', 'api_key_name' => 'k', 'api_private_key' => 'p', 'is_active' => true,
        ]);

        return new CoinbasePerpsExecutor($coinbase);
    }

    public function test_sell_falls_back_to_the_decision_price_when_the_venue_gives_no_usable_average(): void
    {
        $coinbase = \Mockery::mock(CoinbaseService::class);
        $coinbase->shouldReceive('marketContracts')->once()->andReturn([
            'success' => true,
            'success_response' => ['order_id' => 'order-1'],
        ]);
        $coinbase->shouldReceive('getOrder')->andReturn([
            'order' => [
                'status' => 'FILLED',
                'filled_size' => '1',
                'average_filled_price' => '0',
                'total_fees' => '1.20',
            ],
        ]);

        Log::spy();

        // 1 contract of BTC-USD's nano perp = 0.01 BTC (config/desk.php's default perps.map).
        $result = $this->executor($coinbase)->sell('BTC-USD', 0.01, 10_000.0);

        $this->assertSame('filled', $result->status);
        $this->assertEqualsWithDelta(10_000.0, $result->fillPrice, 1e-9, 'falls back to the decision price, not 0.0');
        $this->assertEqualsWithDelta(0.01 * 10_000.0 - 1.20, $result->filledUsd, 1e-9, 'gross is rebuilt off the same fallback basis, not left at 0');
        $this->assertTrue($result->ok(), 'a real fill must still book once a sane basis is recovered — pre-fix this stayed false and Desk::close() left the position open');
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_sell_uses_the_reported_average_when_the_venue_gives_one(): void
    {
        $coinbase = \Mockery::mock(CoinbaseService::class);
        $coinbase->shouldReceive('marketContracts')->once()->andReturn([
            'success' => true,
            'success_response' => ['order_id' => 'order-2'],
        ]);
        $coinbase->shouldReceive('getOrder')->andReturn([
            'order' => [
                'status' => 'FILLED',
                'filled_size' => '1',
                'average_filled_price' => '10200.0',
                'total_fees' => '1.20',
            ],
        ]);

        Log::spy();

        $result = $this->executor($coinbase)->sell('BTC-USD', 0.01, 10_000.0);

        $this->assertEqualsWithDelta(10_200.0, $result->fillPrice, 1e-9);
        $this->assertTrue($result->ok());
        Log::shouldNotHaveReceived('warning');
    }
}
