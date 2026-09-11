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
 * Test-only strategy: proposes a fixed-dollar ticket on each of two products in the SAME scan
 * call, then holds forever. Exercises the backtester's between-candidate bank reservation (finding
 * 10: margin reserves collateral, not the full ticket, between two candidates queued in one bar).
 */
class TwoTicketStrategy implements Strategy
{
    private bool $fired = false;

    public function __construct(
        private readonly string $productA, private readonly float $usdA,
        private readonly string $productB, private readonly float $usdB,
    ) {}

    public function key(): string
    {
        return 'two_ticket_test';
    }

    public function name(): string
    {
        return 'Two Ticket (test fixture)';
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

        return [
            new Candidate(ProductStats::fromArray(['product_id' => $this->productA, 'price' => 1.0]), 2.0, 'two ticket test A'),
            new Candidate(ProductStats::fromArray(['product_id' => $this->productB, 'price' => 1.0]), 1.0, 'two ticket test B'),
        ];
    }

    public function vet(Candidate $candidate, Bank $bank, DeskContext $ctx): Verdict
    {
        return Verdict::pass($candidate, ['two_ticket']);
    }

    public function size(Verdict $verdict, Bank $bank, DeskContext $ctx): SizeDecision
    {
        $usd = $verdict->candidate->productId() === $this->productA ? $this->usdA : $this->usdB;

        return new SizeDecision($verdict, $usd, 0.0, 0.0, true, false, 'two ticket test');
    }

    public function risk(Position $position, ProductStats $stats, DeskContext $ctx): RiskDecision
    {
        return RiskDecision::hold();
    }
}
