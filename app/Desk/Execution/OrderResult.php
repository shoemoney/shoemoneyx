<?php

declare(strict_types=1);

namespace App\Desk\Execution;

use Illuminate\Support\Facades\Log;

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
        /**
         * True when recoverFillBasis() had to fabricate this fill's price and/or notional off the
         * decision price rather than the venue's own readback (round-6 review, MAJOR): slippage
         * against a fabricated price is not measured slippage, it is exactly zero by construction,
         * which let a genuinely bad fill sail past Desk's SLIPPAGE OVER MAX check and overstated
         * Position.pnl_usd. slippageBps() refuses to report a number when this is true.
         */
        public readonly bool $basisRecovered = false,
    ) {}

    /**
     * A degraded fill (filled_size > 0 but no usable price/value from the venue — seen on
     * CoinbaseExecutor polls missing filled_value/average_filled_price) must never book: a zero
     * or missing fillPrice drags the v2 average to zero, which silences every pct_from_avg
     * fail-safe and zeros every ladder target (round-4 review). Every caller of ok() — Desk's
     * entry/add/trim/close bookkeeping — relies on this as the one gate; nothing downstream
     * re-checks fillPrice on its own.
     *
     * filledUsd is deliberately NOT checked here (round-5 review): a genuine exit whose fee
     * exceeds its proceeds (dust, or a per-contract fee floor) has a real, negative filledUsd —
     * that is a losing trade, not a degraded fill, and must still close. fillPrice > 0 already
     * covers the zero/missing-average hazard this existed for.
     */
    public function ok(): bool
    {
        return $this->status === 'filled' && $this->filledQty > 0 && $this->fillPrice !== null && $this->fillPrice > 0;
    }

    /**
     * Recovers a usable (price, notional) pair from a venue poll that reports a real filled
     * quantity but a missing/zero average price, OR an average with no accompanying notional
     * value (round-5 review: a Coinbase poll can report average_filled_price but no
     * filled_value, which bypassed round 4's fallback outright — fillPrice 102, filledUsd -1.20).
     * The one shape every settle() (CoinbaseExecutor, CoinbasePerpsExecutor, CcxtExecutor) must
     * defend against identically, so it lives here once rather than being reimplemented (and
     * drifting) per venue. Falls back to the DECISION price, never a caller's own guess, and logs
     * loudly either way since both shapes mean the venue gave back materially incomplete data.
     *
     * @param  array<string, mixed>  $context  extra fields folded into the warning log for triage
     * @return array{0: ?float, 1: float, 2: bool} [price, notional, recovered]
     */
    public static function recoverFillBasis(float $filledQty, ?float $avg, float $filledValue, float $decisionPrice, string $venue, ?string $orderId, array $context = []): array
    {
        if ($filledQty <= 0) {
            return [$avg, $filledValue, false];
        }
        if ($avg === null || $avg <= 0) {
            Log::warning("{$venue}: filled order missing a usable average price, falling back to the decision price", [
                'order_id' => $orderId, 'filled_qty' => $filledQty, 'filled_value' => $filledValue, 'average' => $avg, ...$context,
            ]);

            return [$decisionPrice, $filledQty * $decisionPrice, true];
        }
        if ($filledValue <= 0) {
            Log::warning("{$venue}: filled order has an average price but no filled value, rebuilding it from the average", [
                'order_id' => $orderId, 'filled_qty' => $filledQty, 'average' => $avg, ...$context,
            ]);

            return [$avg, $filledQty * $avg, true];
        }

        return [$avg, $filledValue, false];
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
        if ($this->basisRecovered || ! $this->fillPrice || $this->decisionPrice <= 0) {
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
