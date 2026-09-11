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
 * Test-only strategy: proposes one entry ticket on the first SCAN, holds through every RISK call
 * except the chosen one ($closeOnCall, counted from 1 the same way TrimAndFundStrategy counts
 * $trimOnCall), where it closes outright. Optionally trims on the call BEFORE that instead of
 * holding, and/or stashes a stop_price in the close decision's meta for the conservative touch
 * policy (strategy review 10).
 */
class CloseOnCallStrategy implements Strategy
{
    private bool $fired = false;

    private int $riskCalls = 0;

    public function __construct(
        private readonly float $usd,
        private readonly string $productId = 'BTC-USD',
        private readonly string $side = 'long',
        private readonly int $closeOnCall = 1,
        private readonly string $closeRule = 'test_close',
        private readonly ?int $trimOnCall = null,
        private readonly float $trimFraction = 0.5,
        private readonly ?float $trimLimitPrice = null,
        private readonly ?float $stopPrice = null,
    ) {}

    public function key(): string
    {
        return 'close_on_call_test';
    }

    public function name(): string
    {
        return 'Close On Call (test fixture)';
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

        return [new Candidate(ProductStats::fromArray(['product_id' => $this->productId, 'price' => 1.0]), 1.0, 'close on call test', side: $this->side)];
    }

    public function vet(Candidate $candidate, Bank $bank, DeskContext $ctx): Verdict
    {
        return Verdict::pass($candidate, ['close_on_call']);
    }

    public function size(Verdict $verdict, Bank $bank, DeskContext $ctx): SizeDecision
    {
        return new SizeDecision($verdict, $this->usd, 0.0, 0.0, true, false, 'close on call test');
    }

    public function risk(Position $position, ProductStats $stats, DeskContext $ctx): RiskDecision
    {
        $this->riskCalls++;
        if ($this->riskCalls === $this->closeOnCall) {
            $meta = $this->stopPrice !== null ? ['stop_price' => $this->stopPrice] : [];

            return RiskDecision::close($this->closeRule, meta: $meta);
        }
        if ($this->trimOnCall !== null && $this->riskCalls === $this->trimOnCall) {
            $meta = $this->stopPrice !== null ? ['stop_price' => $this->stopPrice] : [];

            return RiskDecision::trim('tp', $this->trimFraction, $this->trimLimitPrice, 'test trim', $meta);
        }

        return RiskDecision::hold();
    }
}
