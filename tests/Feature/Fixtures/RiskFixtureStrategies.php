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
 * Test-only strategies whose RISK behaviour is controllable, so a sweep test can make "the strategy a
 * position was opened with" and "the strategy the desk runs now" behave differently.
 */
abstract class RiskFixtureStrategy implements Strategy
{
    /** @var (callable(Position, DeskContext): RiskDecision)|null */
    public static $onRisk = null;

    public static ?DeskContext $lastCtx = null;

    public static function reset(): void
    {
        self::$onRisk = null;
        self::$lastCtx = null;
    }

    public function name(): string
    {
        return $this->key();
    }

    public function defaults(): array
    {
        return [];
    }

    public function scan(array $universe, DeskContext $ctx): array
    {
        return [];
    }

    public function vet(Candidate $candidate, Bank $bank, DeskContext $ctx): Verdict
    {
        throw new \LogicException('not used');
    }

    public function size(Verdict $verdict, Bank $bank, DeskContext $ctx): SizeDecision
    {
        throw new \LogicException('not used');
    }

    public function risk(Position $position, ProductStats $stats, DeskContext $ctx): RiskDecision
    {
        self::$lastCtx = $ctx;

        return self::$onRisk !== null ? (self::$onRisk)($position, $ctx) : RiskDecision::hold();
    }
}

/** Always holds. Stands in for "the strategy the desk was switched TO". */
class HoldRiskStrategy extends RiskFixtureStrategy
{
    public function key(): string
    {
        return 'hold_test';
    }
}

/** Stops every position out. Stands in for "the strategy the position was opened with". */
class StopRiskStrategy extends RiskFixtureStrategy
{
    public function key(): string
    {
        return 'stop_test';
    }

    public function risk(Position $position, ProductStats $stats, DeskContext $ctx): RiskDecision
    {
        return RiskDecision::close('stop_test_stop');
    }
}

/** Runs the static hook (default hold) — for meta-writing and ctx-probing tests. */
class HookRiskStrategy extends RiskFixtureStrategy
{
    public function key(): string
    {
        return 'hook_test';
    }
}
