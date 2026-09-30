<?php

declare(strict_types=1);

namespace App\Desk\Execution;

use App\Models\OrderIntent;

/** A live executor that can settle its own unresolved order intents from the venue's records. */
interface ReconcilesOrders
{
    /**
     * Looks the intent's order up at the venue WITHOUT sending anything. Returns the outcome once the
     * venue reports the order terminal (the caller books it, then resolves the intent), or null while
     * it is still ambiguous. An order the venue provably never received is resolved as 'abandoned'
     * here and also yields null.
     */
    public function reconcile(OrderIntent $intent): ?OrderResult;
}
