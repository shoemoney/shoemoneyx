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
 * Test-only strategy: proposes one entry ticket of a fixed dollar size on the first SCAN, then
 * closes it outright on the very first RISK call -- which lands the same step the entry itself
 * fills on (RISK runs right after step 1's fills), so a one-bar backtest can carry a whole round
 * trip. Isolates return-baseline behaviour (finding 9) from any real strategy's own exit logic.
 */
class InstantCloseStrategy implements Strategy
{
    private bool $fired = false;

    public function __construct(private readonly float $usd, private readonly string $productId = 'BTC-USD') {}

    public function key(): string
    {
        return 'instant_close_test';
    }

    public function name(): string
    {
        return 'Instant Close (test fixture)';
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

        return [new Candidate(ProductStats::fromArray(['product_id' => $this->productId, 'price' => 1.0]), 1.0, 'instant close test')];
    }

    public function vet(Candidate $candidate, Bank $bank, DeskContext $ctx): Verdict
    {
        return Verdict::pass($candidate, ['instant_close']);
    }

    public function size(Verdict $verdict, Bank $bank, DeskContext $ctx): SizeDecision
    {
        return new SizeDecision($verdict, $this->usd, 0.0, 0.0, true, false, 'instant close test');
    }

    public function risk(Position $position, ProductStats $stats, DeskContext $ctx): RiskDecision
    {
        return RiskDecision::close('instant');
    }
}
