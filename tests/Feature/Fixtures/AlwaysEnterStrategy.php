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
 * Test double: enters every candidate in the universe for a fixed dollar size (capped at free
 * cash) and never manages risk. Used to prove the arena's multi-seat plumbing — independent
 * fills, positions and cash per seat — deterministically, without depending on a real
 * strategy's statistical signal (mr's z-score, custom's rate-of-change ranking, ...) firing.
 */
class AlwaysEnterStrategy implements Strategy
{
    public function __construct(private float $sizeUsd = 100.0) {}

    public function key(): string
    {
        return 'always_enter';
    }

    public function name(): string
    {
        return 'Always enter (test double)';
    }

    public function defaults(): array
    {
        return [];
    }

    public function scan(array $universe, DeskContext $ctx): array
    {
        return array_map(fn (ProductStats $s) => new Candidate($s, 1.0, 'test double always enters'), $universe);
    }

    public function vet(Candidate $candidate, Bank $bank, DeskContext $ctx): Verdict
    {
        return Verdict::pass($candidate, ['always_enter']);
    }

    public function size(Verdict $verdict, Bank $bank, DeskContext $ctx): SizeDecision
    {
        $dollars = min($this->sizeUsd, $bank->freeCash());

        return new SizeDecision($verdict, $dollars, 0.0, 0.0, true, false, 'fixed test size');
    }

    public function risk(Position $position, ProductStats $stats, DeskContext $ctx): RiskDecision
    {
        return RiskDecision::hold();
    }
}
