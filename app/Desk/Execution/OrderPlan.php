<?php

declare(strict_types=1);

namespace App\Desk\Execution;

/**
 * What CoinbasePerpsExecutor::plan() worked out before send() would touch the network for an order:
 * the mapped perp, the sized contracts/notional, and the gate's verdict. $rejection, when set, is the
 * exact reason send() would return OrderResult::rejected before calling marketContracts — no perps.map
 * entry, under one contract, the gate refusing new risk, or the margin-check API call failing.
 */
final readonly class OrderPlan
{
    public function __construct(
        public string $spotPid,
        public ?string $perpProductId,
        public string $side,
        public int $contracts,
        public float $notional,
        public ?PerpsGate $gate,
        public ?string $rejection,
        /** OrderResult status send() reports for the rejection: 'rejected' for a rule, 'error' when the margin check itself failed. */
        public string $rejectionStatus = 'rejected',
    ) {}
}
