<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Desk\Execution\Perps;
use App\Desk\Execution\PerpsSession;
use App\Exchange\Coinbase\Api\CoinbaseApiException;
use App\Exchange\Coinbase\Api\CoinbaseService;
use App\Exchange\Coinbase\CoinbasePerpsExecutor;
use App\Models\CoinbaseAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Tests\TestCase;

/**
 * Perps exits are plain market IOC orders, so with no venue position (liquidated, ADL'd, closed by
 * hand) a stop SELL would open a fresh short and book it as a close. Every exit is therefore capped
 * at the same-side size /cfm/positions reports and refused when the venue is flat or on the other side.
 */
class CoinbasePerpsExecutorExitGuardTest extends TestCase
{
    use RefreshDatabase;

    private function executor(CoinbaseService $coinbase): CoinbasePerpsExecutor
    {
        CoinbaseAccount::create([
            'name' => 'test', 'api_key_name' => 'k', 'api_private_key' => 'p', 'is_active' => true,
        ]);

        return new CoinbasePerpsExecutor($coinbase);
    }

    /** @return array<string, mixed> one row of GET /cfm/positions */
    private function venue(string $side, string $contracts): array
    {
        return ['product_id' => 'BIP-20DEC30-CDE', 'side' => $side, 'number_of_contracts' => $contracts];
    }

    private function fillOnce(CoinbaseService $coinbase, int $contracts): void
    {
        $coinbase->shouldReceive('marketContracts')->once()
            ->with(\Mockery::any(), 'BIP-20DEC30-CDE', \Mockery::any(), $contracts, \Mockery::any())
            ->andReturn(['success' => true, 'success_response' => ['order_id' => 'o-1']]);
        $coinbase->shouldReceive('getOrder')->andReturn(['order' => [
            'status' => 'FILLED', 'filled_size' => (string) $contracts, 'average_filled_price' => '10000', 'total_fees' => '0.1',
        ]]);
    }

    public function test_sell_is_rejected_when_the_venue_is_flat(): void
    {
        $coinbase = \Mockery::mock(CoinbaseService::class);
        $coinbase->shouldReceive('futuresPositions')->andReturn([]);
        $coinbase->shouldNotReceive('marketContracts');

        $result = $this->executor($coinbase)->sell('BTC-USD', 0.01, 10_000.0);

        $this->assertSame('rejected', $result->status);
        $this->assertStringContainsString('no long position on the venue', (string) $result->note);
        $this->assertDatabaseHas('desk_events', ['level' => 'error', 'agent' => 'PERPS']);
    }

    public function test_sell_is_rejected_when_the_venue_holds_the_opposite_side(): void
    {
        $coinbase = \Mockery::mock(CoinbaseService::class);
        $coinbase->shouldReceive('futuresPositions')->andReturn([$this->venue('SHORT', '2')]);
        $coinbase->shouldNotReceive('marketContracts');

        $result = $this->executor($coinbase)->sell('BTC-USD', 0.01, 10_000.0);

        $this->assertSame('rejected', $result->status);
    }

    public function test_cover_is_rejected_when_the_venue_holds_a_long(): void
    {
        $coinbase = \Mockery::mock(CoinbaseService::class);
        $coinbase->shouldReceive('futuresPositions')->andReturn([$this->venue('LONG', '2')]);
        $coinbase->shouldNotReceive('marketContracts');

        $result = $this->executor($coinbase)->coverShort('BTC-USD', 0.01, 10_000.0, 100.0);

        $this->assertSame('rejected', $result->status);
    }

    public function test_sell_is_capped_at_the_venue_size(): void
    {
        $coinbase = \Mockery::mock(CoinbaseService::class);
        $coinbase->shouldReceive('futuresPositions')->andReturn([$this->venue('LONG', '1')]);
        $this->fillOnce($coinbase, 1);

        $result = $this->executor($coinbase)->sell('BTC-USD', 0.03, 10_000.0);

        $this->assertSame('filled', $result->status);
        $this->assertEqualsWithDelta(0.01, $result->filledQty, 1e-12);
    }

    public function test_a_trim_within_the_venue_size_is_sent_unchanged(): void
    {
        $coinbase = \Mockery::mock(CoinbaseService::class);
        $coinbase->shouldReceive('futuresPositions')->andReturn([$this->venue('LONG', '5')]);
        $this->fillOnce($coinbase, 2);

        $this->assertSame('filled', $this->executor($coinbase)->sell('BTC-USD', 0.02, 10_000.0)->status);
    }

    public function test_cover_short_is_capped_at_the_venue_size(): void
    {
        $coinbase = \Mockery::mock(CoinbaseService::class);
        $coinbase->shouldReceive('futuresPositions')->andReturn([$this->venue('SHORT', '2')]);
        $this->fillOnce($coinbase, 2);

        $this->assertSame('filled', $this->executor($coinbase)->coverShort('BTC-USD', 0.05, 10_000.0, 100.0)->status);
    }

    public function test_exit_fails_closed_when_the_positions_read_fails(): void
    {
        $coinbase = \Mockery::mock(CoinbaseService::class);
        $coinbase->shouldReceive('futuresPositions')->andThrow(new CoinbaseApiException('boom'));
        $coinbase->shouldNotReceive('marketContracts');

        $result = $this->executor($coinbase)->sell('BTC-USD', 0.01, 10_000.0);

        $this->assertSame('error', $result->status);
    }

    public function test_exit_fails_closed_when_the_positions_read_times_out(): void
    {
        $coinbase = \Mockery::mock(CoinbaseService::class);
        $coinbase->shouldReceive('futuresPositions')->andThrow(new ConnectionException('timed out'));
        $coinbase->shouldNotReceive('marketContracts');

        $result = $this->executor($coinbase)->coverShort('BTC-USD', 0.01, 10_000.0, 100.0);

        $this->assertSame('error', $result->status);
    }

    public function test_the_cached_session_is_dropped_after_an_order_is_submitted(): void
    {
        $coinbase = \Mockery::mock(CoinbaseService::class);
        $coinbase->shouldReceive('futuresPositions')->andReturn([$this->venue('LONG', '1')]);
        $this->fillOnce($coinbase, 1);
        $executor = $this->executor($coinbase);

        $prop = new \ReflectionProperty($executor, 'cachedSession');
        $prop->setValue($executor, PerpsSession::fromApi([], []));

        $executor->sell('BTC-USD', 0.01, 10_000.0);

        $this->assertNull($prop->getValue($executor));
    }

    public function test_contracts_for_qty_floors_like_the_paper_lot(): void
    {
        $this->assertSame(1, Perps::contractsForQty('BTC-USD', 0.019));
        $this->assertSame(3, Perps::contractsForQty('BTC-USD', 0.03), '0.03/0.01 is 2.9999999999999996 in floats');
        $this->assertSame(0, Perps::contractsForQty('BTC-USD', 0.004));
    }
}
