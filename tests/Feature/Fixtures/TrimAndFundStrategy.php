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
 * Test-only strategy: proposes one entry ticket on the first SCAN like FixedTicketStrategy, but also
 * fires a TRIM on a chosen RISK call so BacktestFeesTest can pin the maker-fee path down to a
 * closed-form ending_equity. $trimOnCall counts RISK calls from 1 (the bar the entry itself fills on,
 * since RISK runs on the same step right after the fill).
 */
class TrimAndFundStrategy implements Strategy
{
    private bool $fired = false;

    private int $riskCalls = 0;

    public function __construct(
        private readonly float $usd,
        private readonly string $productId = 'BTC-USD',
        private readonly string $side = 'long',
        private readonly ?int $trimOnCall = null,
        private readonly float $trimFraction = 0.5,
        private readonly ?float $trimLimitPrice = null,
    ) {}

    public function key(): string
    {
        return 'trim_fund_test';
    }

    public function name(): string
    {
        return 'Trim & Fund (test fixture)';
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

        return [new Candidate(ProductStats::fromArray(['product_id' => $this->productId, 'price' => 1.0]), 1.0, 'trim fund test', side: $this->side)];
    }

    public function vet(Candidate $candidate, Bank $bank, DeskContext $ctx): Verdict
    {
        return Verdict::pass($candidate, ['trim_fund_test']);
    }

    public function size(Verdict $verdict, Bank $bank, DeskContext $ctx): SizeDecision
    {
        return new SizeDecision($verdict, $this->usd, 0.0, 0.0, true, false, 'trim fund test');
    }

    public function risk(Position $position, ProductStats $stats, DeskContext $ctx): RiskDecision
    {
        $this->riskCalls++;
        if ($this->trimOnCall !== null && $this->riskCalls === $this->trimOnCall) {
            return RiskDecision::trim('tp', $this->trimFraction, $this->trimLimitPrice, 'test trim');
        }

        return RiskDecision::hold();
    }
}
