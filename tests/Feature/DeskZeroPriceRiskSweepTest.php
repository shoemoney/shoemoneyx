<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Contracts\Strategy;
use App\Desk\Data\Bank;
use App\Desk\Data\Candidate as CandidateRow;
use App\Desk\Data\ProductStats;
use App\Desk\Data\RiskDecision;
use App\Desk\Data\SizeDecision;
use App\Desk\Desk;
use App\Desk\DeskContext;
use App\Desk\Execution\Executor;
use App\Desk\Execution\OrderResult;
use App\Models\Position;
use App\Models\Product;
use App\Services\Market\ProductStatsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Round-6 review, BLOCKER 3: ProductStatsBuilder::fromBars() returns price 0.0 (not null) for a
 * product with no closed 1H bars yet, and Desk::statsWithRetries() passes that straight through.
 * Pre-fix, runRiskSweep() called $p->markPrice(0.0) — stamping a real position's last_price to
 * zero — and then handed the strategy a price-0 stats row, which JsonPluginStrategy's
 * reentryArmDecision() could divide by directly. The sweep must skip a price-0 row instead.
 */
class DeskZeroPriceRiskSweepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'desk.mode' => 'paper']);
    }

    private function fakeExecutor(): Executor
    {
        return new class implements Executor
        {
            public function mode(): string
            {
                return 'paper';
            }

            public function buy(string $productId, float $usd, float $decisionPrice): OrderResult
            {
                throw new \LogicException('not used in this test');
            }

            public function sell(string $productId, float $qty, float $decisionPrice, float $entryUsdShare = 0.0, bool $maker = false): OrderResult
            {
                throw new \LogicException('not used in this test');
            }

            public function openShort(string $productId, float $usd, float $decisionPrice): OrderResult
            {
                throw new \LogicException('not used in this test');
            }

            public function coverShort(string $productId, float $qty, float $decisionPrice, float $entryUsdShare, bool $maker = false): OrderResult
            {
                throw new \LogicException('not used in this test');
            }

            public function cash(): float
            {
                return 0.0;
            }
        };
    }

    public function test_a_zero_price_stats_row_is_skipped_before_it_can_stamp_a_zero_last_price(): void
    {
        Product::create(['product_id' => 'BTC-USD', 'base_currency' => 'BTC', 'quote_currency' => 'USD']);
        $position = Position::create([
            'mode' => 'paper', 'strategy' => 'probe', 'product_id' => 'BTC-USD', 'side' => 'long', 'status' => 'open',
            'quantity' => 1.0, 'entry_price' => 100.0, 'entry_usd' => 100.0, 'fees_usd' => 0.0,
            'peak_price' => 100.0, 'last_price' => 105.0, 'opened_at' => now()->subMinutes(10), 'meta' => [],
        ]);

        $strategy = new class implements Strategy
        {
            public bool $riskCalled = false;

            public function key(): string
            {
                return 'probe';
            }

            public function name(): string
            {
                return 'probe';
            }

            public function defaults(): array
            {
                return [];
            }

            public function scan(array $candidates, DeskContext $ctx): array
            {
                return [];
            }

            public function vet(CandidateRow $c, Bank $b, DeskContext $ctx): \App\Desk\Data\Verdict
            {
                throw new \LogicException('not used in this test');
            }

            public function size(\App\Desk\Data\Verdict $v, Bank $b, DeskContext $ctx): SizeDecision
            {
                throw new \LogicException('not used in this test');
            }

            public function risk(Position $p, ProductStats $s, DeskContext $ctx): RiskDecision
            {
                $this->riskCalled = true;

                return RiskDecision::hold('unreachable');
            }
        };

        $zeroStats = ProductStats::fromArray([
            'product_id' => 'BTC-USD', 'price' => 0.0,
            'volume_h24_usd' => 0, 'volume_h1_usd' => 0, 'volume_h6_usd' => 0,
            'price_change_h24_pct' => 0, 'spread_bps' => 0, 'candles_h1_count' => 0,
        ]);
        $builder = \Mockery::mock(ProductStatsBuilder::class);
        $builder->shouldReceive('live')->andReturn($zeroStats);
        $this->app->instance(ProductStatsBuilder::class, $builder);

        $desk = app(Desk::class);
        $deskStats = new \ReflectionProperty(Desk::class, 'stats');
        $deskStats->setAccessible(true);
        $deskStats->setValue($desk, $builder);

        $out = $desk->runRiskSweep($strategy, $this->fakeExecutor());

        $this->assertFalse($strategy->riskCalled, 'a price-0 stats row must be skipped before the strategy ever sees it');
        $this->assertCount(0, $out, 'a skipped position is not reported as any action for this sweep');

        $position->refresh();
        $this->assertEqualsWithDelta(105.0, $position->last_price, 1e-9, 'a price-0 stats row must never overwrite a real last_price with 0');
        $this->assertSame('open', $position->status);
    }
}
