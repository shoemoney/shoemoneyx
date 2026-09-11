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
 * Test-only strategy: proposes exactly one entry ticket of a fixed dollar size on the first
 * SCAN, then holds forever. Isolates the Backtester's entry-fill sizing (Lot::forUsd routing)
 * from any real strategy's own ranking/vetting/Kelly-sizing logic.
 */
class FixedTicketStrategy implements Strategy
{
    private bool $fired = false;

    public function __construct(private readonly float $usd, private readonly string $productId = 'BTC-USD', private readonly string $side = 'long') {}

    public function key(): string
    {
        return 'fixed_ticket_test';
    }

    public function name(): string
    {
        return 'Fixed Ticket (test fixture)';
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

        return [new Candidate(ProductStats::fromArray(['product_id' => $this->productId, 'price' => 1.0]), 1.0, 'fixed ticket test', side: $this->side)];
    }

    public function vet(Candidate $candidate, Bank $bank, DeskContext $ctx): Verdict
    {
        return Verdict::pass($candidate, ['fixed_ticket']);
    }

    public function size(Verdict $verdict, Bank $bank, DeskContext $ctx): SizeDecision
    {
        return new SizeDecision($verdict, $this->usd, 0.0, 0.0, true, false, 'fixed ticket');
    }

    public function risk(Position $position, ProductStats $stats, DeskContext $ctx): RiskDecision
    {
        return RiskDecision::hold();
    }
}
