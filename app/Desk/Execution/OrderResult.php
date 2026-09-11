<?php

declare(strict_types=1);

namespace App\Desk\Execution;

/** What FILLS reports back. Honest: partials are partial, slippage is loud. */
final class OrderResult
{
    public function __construct(
        public readonly string $status,          // filled|rejected|fee_floor|error
        public readonly float $requestedUsd,
        public readonly float $filledUsd,
        public readonly float $filledQty,
        public readonly float $decisionPrice,
        public readonly ?float $fillPrice,
        public readonly float $feeUsd,
        public readonly bool $partial = false,
        public readonly ?string $venueOrderId = null,
        public readonly array $raw = [],
        public readonly ?string $note = null,
        /**
         * Paper executors only: the cash-ledger write this fill still owes, deferred so the caller can
         * run it INSIDE the same DB transaction as the fill/position writes — a rollback then undoes the
         * cash movement too, instead of leaving cash moved while the position stays open at full size.
         * Null for live executors (the venue already settled cash; there is nothing local left to write).
         */
        public readonly ?\Closure $ledgerWrite = null,
    ) {}

    public function ok(): bool
    {
        return $this->status === 'filled' && $this->filledQty > 0;
    }

    /** Runs the deferred ledger write, if this fill carries one. No-op for live fills. */
    public function writeLedger(): void
    {
        if ($this->ledgerWrite !== null) {
            ($this->ledgerWrite)();
        }
    }

    public function slippageBps(string $side): ?float
    {
        if (! $this->fillPrice || $this->decisionPrice <= 0) {
            return null;
        }
        $bps = ($this->fillPrice / $this->decisionPrice - 1) * 10_000;

        return round($side === 'BUY' ? $bps : -$bps, 2);   // positive = worse than decision
    }

    public function feePct(): float
    {
        return $this->filledUsd > 0 ? round($this->feeUsd / $this->filledUsd, 5) : 0.0;
    }

    public static function rejected(string $status, float $requestedUsd, float $decisionPrice, string $note): self
    {
        return new self($status, $requestedUsd, 0, 0, $decisionPrice, null, 0, false, null, [], $note);
    }
}
