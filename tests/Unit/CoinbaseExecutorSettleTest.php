<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exchange\Coinbase\Api\CoinbaseService;
use App\Exchange\Coinbase\CoinbaseExecutor;
use App\Models\CoinbaseAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Round-4 review, finding 1 (BLOCKER): a poll can report filled_size > 0 with no filled_value
 * and no average_filled_price at all. Pre-fix, settle() derived a 0.0 average (filledValue /
 * filledQty = 0 / qty); OrderResult::ok() now refuses that outright, so settle() must recover a
 * sane basis itself — the decision price, logged loudly — rather than hand back a fill nothing
 * downstream can book.
 */
class CoinbaseExecutorSettleTest extends TestCase
{
    use RefreshDatabase;

    private function executor(CoinbaseService $coinbase): CoinbaseExecutor
    {
        CoinbaseAccount::create([
            'name' => 'test', 'api_key_name' => 'k', 'api_private_key' => 'p', 'is_active' => true,
        ]);

        return new CoinbaseExecutor($coinbase);
    }

    public function test_buy_falls_back_to_the_decision_price_when_the_venue_gives_no_usable_average(): void
    {
        $coinbase = \Mockery::mock(CoinbaseService::class);
        $coinbase->shouldReceive('marketBuy')->once()->andReturn([
            'success' => true,
            'success_response' => ['order_id' => 'order-1'],
        ]);
        $coinbase->shouldReceive('getOrder')->andReturn([
            'order' => [
                'status' => 'FILLED',
                'filled_size' => '2.0',
                'filled_value' => '0',
                'average_filled_price' => '0',
                'total_fees' => '1.20',
            ],
        ]);

        Log::spy();

        $result = $this->executor($coinbase)->buy('BTC-USD', 200.0, 100.0);

        $this->assertSame('filled', $result->status);
        $this->assertEqualsWithDelta(100.0, $result->fillPrice, 1e-9, 'falls back to the decision price, not 0.0');
        $this->assertEqualsWithDelta(200.0 + 1.20, $result->filledUsd, 1e-9, 'filledUsd is rebuilt off the same fallback basis, not left at just the fee');
        $this->assertTrue($result->ok(), 'a real fill must still book once a sane basis is recovered');
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_buy_uses_the_reported_average_when_the_venue_gives_one(): void
    {
        $coinbase = \Mockery::mock(CoinbaseService::class);
        $coinbase->shouldReceive('marketBuy')->once()->andReturn([
            'success' => true,
            'success_response' => ['order_id' => 'order-2'],
        ]);
        $coinbase->shouldReceive('getOrder')->andReturn([
            'order' => [
                'status' => 'FILLED',
                'filled_size' => '2.0',
                'filled_value' => '204.0',
                'average_filled_price' => '102.0',
                'total_fees' => '1.20',
            ],
        ]);

        Log::spy();

        $result = $this->executor($coinbase)->buy('BTC-USD', 200.0, 100.0);

        $this->assertEqualsWithDelta(102.0, $result->fillPrice, 1e-9);
        $this->assertEqualsWithDelta(204.0 + 1.20, $result->filledUsd, 1e-9);
        $this->assertTrue($result->ok());
        Log::shouldNotHaveReceived('warning');
    }

    /**
     * Round-5 review, IMPORTANT 4(a): average_filled_price present (real) but filled_value
     * absent bypassed round 4's fallback outright (it only checked the average) — fillPrice 102,
     * filledUsd = 0 - fee = negative, and ok() (still filledUsd > 0 at the time) refused a real
     * fill. Fixed by widening the recovery, not by the filledUsd removal alone.
     */
    public function test_buy_rebuilds_filled_value_when_the_average_is_present_but_the_value_is_not(): void
    {
        $coinbase = \Mockery::mock(CoinbaseService::class);
        $coinbase->shouldReceive('marketBuy')->once()->andReturn([
            'success' => true,
            'success_response' => ['order_id' => 'order-3'],
        ]);
        $coinbase->shouldReceive('getOrder')->andReturn([
            'order' => [
                'status' => 'FILLED',
                'filled_size' => '2.0',
                'filled_value' => '0',
                'average_filled_price' => '102.0',
                'total_fees' => '1.20',
            ],
        ]);

        Log::spy();

        $result = $this->executor($coinbase)->buy('BTC-USD', 200.0, 100.0);

        $this->assertEqualsWithDelta(102.0, $result->fillPrice, 1e-9, 'the real average must not be discarded');
        $this->assertEqualsWithDelta(2.0 * 102.0 + 1.20, $result->filledUsd, 1e-9, 'filled_value must be rebuilt from the real average, not left at 0');
        $this->assertTrue($result->ok());
        Log::shouldHaveReceived('warning')->once();
    }
}
