<?php

declare(strict_types=1);

namespace App\Desk\Contracts;

use App\Desk\Data\Bank;
use App\Desk\Data\Candidate;
use App\Desk\Data\ProductStats;
use App\Desk\Data\RiskDecision;
use App\Desk\Data\SizeDecision;
use App\Desk\Data\Verdict;
use App\Desk\DeskContext;
use App\Models\Position;

/**
 * A strategy owns the four decisions of the desk. The pipeline (App\Desk\Desk)
 * owns everything else: data, persistence, execution, supervision, reporting.
 *
 * Implement this (or extend BaseDeskStrategy) and register the class in
 * config/desk.php `strategies`, then set DESK_STRATEGY=<key>.
 */
interface Strategy
{
    public function key(): string;

    public function name(): string;

    /**
     * Default tunables for this strategy. Every key can be overridden from the
     * dashboard (settings table) — read them via $ctx->param('key').
     *
     * @return array<string, mixed>
     */
    public function defaults(): array;

    /**
     * SCAN: rank the universe. Return at most $ctx->param('scan.max_candidates')
     * candidates, best first, rank filled by the pipeline. Fewer than 3 clear
     * the rules? Return fewer. Never pad.
     *
     * @param  array<int, ProductStats>  $universe
     * @return array<int, Candidate>
     */
    public function scan(array $universe, DeskContext $ctx): array;

    /** VET: cheapest checks first, first failure ends the check. */
    public function vet(Candidate $candidate, Bank $bank, DeskContext $ctx): Verdict;

    /** SIZE: how many dollars. Never whether. */
    public function size(Verdict $verdict, Bank $bank, DeskContext $ctx): SizeDecision;

    /** RISK: evaluated on its own timer for every open position. Final authority. */
    public function risk(Position $position, ProductStats $stats, DeskContext $ctx): RiskDecision;
}
