<?php

declare(strict_types=1);

namespace Tests\Feature\Fixtures;

use App\Desk\Contracts\Strategy;
use App\Desk\Data\Bank;
use App\Desk\Data\Candidate;
use App\Desk\Data\ProductStats;
use App\Desk\Data\RiskDecision;
use App\Desk\Data\SizeDecision;
use App\Desk\Data\Verdict;
use App\Desk\DeskContext;
use App\Models\Position;

/**
 * Test-only strategy: proposes one entry ticket on the first SCAN like TrimAndFundStrategy, then can
 * fire a TRIM on one chosen RISK call and a RiskDecision::add() (a take-profit-reentry-style re-buy) on a
 * later one, so a Backtest*Reentry* test can pin the re-entry's fee/quantity/cash bookkeeping down to
 * a closed-form ending_equity without exercising any real strategy's own retrace math.
 */
class ReentryFixtureStrategy implements Strategy
{
    private bool $fired = false;

    private int $riskCalls = 0;

    public function __construct(
        private readonly float $usd,
        private readonly string $productId = 'BTC-USD',
        private readonly string $side = 'long',
        private readonly ?int $trimOnCall = null,
        private readonly float $trimFraction = 0.5,
        private readonly ?int $addOnCall = null,
        private readonly float $addDollars = 0.0,
    ) {}

    public function key(): string
    {
        return 'reentry_test';
    }

    public function name(): string
    {
        return 'Reentry (test fixture)';
    }

    public function defaults(): array
    {
        return [];
    }

    public function scan(array $universe, DeskContext $ctx): array
    {
        if ($this->fired) {
            return [];
        }
        $this->fired = true;

        return [new Candidate(ProductStats::fromArray(['product_id' => $this->productId, 'price' => 1.0]), 1.0, 'reentry test', side: $this->side)];
    }

    public function vet(Candidate $candidate, Bank $bank, DeskContext $ctx): Verdict
    {
        return Verdict::pass($candidate, ['reentry_test']);
    }

    public function size(Verdict $verdict, Bank $bank, DeskContext $ctx): SizeDecision
    {
        return new SizeDecision($verdict, $this->usd, 0.0, 0.0, true, false, 'reentry test');
    }

    public function risk(Position $position, ProductStats $stats, DeskContext $ctx): RiskDecision
    {
        $this->riskCalls++;
        if ($this->trimOnCall !== null && $this->riskCalls === $this->trimOnCall) {
            return RiskDecision::trim('tp', $this->trimFraction, null, 'test trim');
        }
        if ($this->addOnCall !== null && $this->riskCalls === $this->addOnCall) {
            return RiskDecision::add('tp_reentry', $this->addDollars, $stats->price, 'test reentry');
        }

        return RiskDecision::hold();
    }
}
