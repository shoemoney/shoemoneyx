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
 * Test-only strategy: proposes two entry tickets on the first SCAN, one per product id, so a test
 * can drive Desk::cycle() through two VET->SIZE->FILLS turns in one call. $onSecondVet fires right
 * as the second candidate is vetted — after the first has already been sized and filled — so a test
 * can simulate an operator hitting halt mid-cycle and assert the second entry's submission is
 * blocked (finding 15: halt must be re-checked immediately before each entry, not only at cycle start).
 */
class TwoEntryHaltStrategy implements Strategy
{
    private bool $fired = false;

    /** @param  array<int, string>  $productIds */
    public function __construct(
        private readonly float $usd,
        private readonly array $productIds,
        private readonly ?\Closure $onSecondVet = null,
    ) {}

    public function key(): string
    {
        return 'two_entry_halt_test';
    }

    public function name(): string
    {
        return 'Two Entry Halt (test fixture)';
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

        return array_map(
            fn (string $pid) => new Candidate(ProductStats::fromArray(['product_id' => $pid, 'price' => 1.0]), 1.0, 'two entry halt test'),
            $this->productIds,
        );
    }

    public function vet(Candidate $candidate, Bank $bank, DeskContext $ctx): Verdict
    {
        if ($this->onSecondVet !== null && $candidate->productId() === $this->productIds[1]) {
            ($this->onSecondVet)();
        }

        return Verdict::pass($candidate, ['two_entry_halt_test']);
    }

    public function size(Verdict $verdict, Bank $bank, DeskContext $ctx): SizeDecision
    {
        return new SizeDecision($verdict, $this->usd, 0.0, 0.0, true, false, 'two entry halt test');
    }

    public function risk(Position $position, ProductStats $stats, DeskContext $ctx): RiskDecision
    {
        return RiskDecision::hold();
    }
}
