<?php

declare(strict_types=1);

namespace App\Desk\Execution;

use App\Models\OrderIntent;

/** A live executor that can settle its own unresolved order intents from the venue's records. */
interface ReconcilesOrders
{
    /** The executor's identity ('coinbase_spot', 'coinbase_perps', 'ccxt:kraken'); it only ever reconciles its own intents. */
    public function orderVenue(): string;

    /**
     * Looks the intent's order up at the venue. Pure: sends nothing and writes nothing, so the caller
     * (Desk, under the product lock, after re-checking the intent is still pending) is the only one
     * that books or resolves. Returns the outcome once the venue reports the order terminal, else null.
     */
    public function reconcile(OrderIntent $intent): ?OrderResult;

    /** True only when the lookup succeeded, the venue has no such order, and the grace window since the last send has passed. */
    public function confirmedAbsent(OrderIntent $intent): bool;
}
