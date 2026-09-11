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
 * Test-only strategy: opens a fixed ticket like FixedTicketStrategy, but flips the product's
 * perps contract_size (config desk.perps.map) to a coarser value the first time RISK runs for
 * it -- mimicking a holding that was opened before whole-contract sizing existed (or before a
 * contract_size change): the position's quantity is a whole number of the OLD contract size but
 * not of the new one, so any exit that routes it back through Lot::forQty with the new spec sees
 * a sub-contract quantity. Isolates the CLOSE/LIQUIDATION/END-OF-TEST zero-lot guard from the
 * unrelated question of how such a holding could ever be opened in the first place.
 */
class LegacyFractionalHoldingStrategy implements Strategy
{
    private bool $fired = false;

    private int $riskCalls = 0;

    public function __construct(
        private readonly float $usd,
        private readonly string $productId,
        private readonly float $newContractSize,
    ) {}

    public function key(): string
    {
        return 'legacy_fractional_holding_test';
    }

    public function name(): string
    {
        return 'Legacy Fractional Holding (test fixture)';
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

        return [new Candidate(ProductStats::fromArray(['product_id' => $this->productId, 'price' => 1.0]), 1.0, 'legacy fractional holding test')];
    }

    public function vet(Candidate $candidate, Bank $bank, DeskContext $ctx): Verdict
    {
        return Verdict::pass($candidate, ['legacy_fractional_holding_test']);
    }

    public function size(Verdict $verdict, Bank $bank, DeskContext $ctx): SizeDecision
    {
        return new SizeDecision($verdict, $this->usd, 0.0, 0.0, true, false, 'legacy fractional holding test');
    }

    public function risk(Position $position, ProductStats $stats, DeskContext $ctx): RiskDecision
    {
        $this->riskCalls++;
        if ($this->riskCalls === 1) {
            config(["desk.perps.map.{$this->productId}.contract_size" => $this->newContractSize]);
        }

        return RiskDecision::hold();
    }
}
