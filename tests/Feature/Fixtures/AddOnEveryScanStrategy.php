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
 * Test-only strategy: proposes a ticket for the SAME product on EVERY scan() call (no "fired" guard,
 * unlike FixedTicketStrategy), carrying a distinct extra['signal_ts'] per call — mirrors a real
 * strategy's own per-call signal timestamp without depending on any strategy's live signal
 * engine. Lets a test drive Desk::cycle() twice and get an ENTRY on the first call, an ADD on the
 * second, so the add-restraint meta stamped where the fill is actually recorded (Desk::doEnter — see
 * initial_cost_usd / last_action_ts / last_signal_ts) can be asserted end to end.
 */
class AddOnEveryScanStrategy implements Strategy
{
    private int $calls = 0;

    /** @param  array<int, int>  $signalTimestamps  one per scan() call, in order; the last value repeats past the end */
    public function __construct(
        private readonly float $usd,
        private readonly string $productId,
        private readonly array $signalTimestamps,
    ) {}

    public function key(): string
    {
        return 'add_on_every_scan_test';
    }

    public function name(): string
    {
        return 'Add On Every Scan (test fixture)';
    }

    public function defaults(): array
    {
        return [];
    }

    public function scan(array $universe, DeskContext $ctx): array
    {
        $i = min($this->calls, count($this->signalTimestamps) - 1);
        $ts = $this->signalTimestamps[$i];
        $this->calls++;

        return [new Candidate(
            ProductStats::fromArray(['product_id' => $this->productId, 'price' => 1.0, 'extra' => ['signal_ts' => $ts]]),
            1.0,
            'add on every scan test',
        )];
    }

    public function vet(Candidate $candidate, Bank $bank, DeskContext $ctx): Verdict
    {
        return Verdict::pass($candidate, ['add_on_every_scan_test']);
    }

    public function size(Verdict $verdict, Bank $bank, DeskContext $ctx): SizeDecision
    {
        return new SizeDecision($verdict, $this->usd, 0.0, 0.0, true, false, 'add on every scan test');
    }

    public function risk(Position $position, ProductStats $stats, DeskContext $ctx): RiskDecision
    {
        return RiskDecision::hold();
    }
}
