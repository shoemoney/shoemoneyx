<?php

declare(strict_types=1);

namespace App\Desk\Execution\Concerns;

use App\Desk\Execution\OrderBudget;
use App\Desk\Execution\OrderResult;
use App\Models\OrderIntent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The one rule for every LIVE executor: an order is never sent twice and never lost.
 *
 *  - The intent (with its client_order_id, the id the venue dedups on) is persisted BEFORE the request
 *    leaves the box and stays 'pending' until the venue's own order record proves the outcome.
 *  - Anything ambiguous (a transport error on create, a readback that never answered, an order still
 *    working after polling) is an 'unknown' result, never 'rejected': the order may be live.
 *  - While a product has a pending intent nothing new is sent for it. A same-method call reconciles the
 *    pending order by looking it up at the venue and reports THAT outcome, so the caller books the
 *    original order exactly once; a different-method call is refused until the intent resolves.
 *  - Only a lookup that succeeded, found nothing, and waited out the grace window re-sends — under the
 *    SAME client_order_id, so even a late-indexed original is still deduped by the venue.
 *
 * Callers must hold the desk's per-product mutate lock (Desk does), which serialises this per product.
 */
trait SubmitsOrdersIdempotently
{
    /** Transport-level or 5xx-style failure after which the venue may nevertheless hold the order. */
    abstract protected function ambiguousFailure(\Throwable $e): bool;

    /** Non-null when the venue's create response is a definitive refusal (nothing was placed). */
    abstract protected function createRejection(array $create): ?string;

    abstract protected function createdOrderId(array $create): ?string;

    /** The order record for this intent, or null when the venue provably has none. Throws when it cannot tell. */
    abstract protected function lookupOrder(OrderIntent $intent): ?array;

    /** Readback of an order whose venue id is known. Throws on failure. */
    abstract protected function fetchOrderRecord(OrderIntent $intent): array;

    abstract protected function orderTerminal(array $order): bool;

    /** A usable order record derived from the create response alone, for venues whose create already reports the fill. */
    protected function recordFromCreate(array $create): ?array
    {
        return null;
    }

    /** Which executor owns an intent (spot and perps are both mode 'live'); reconcile only ever touches its own. */
    abstract public function orderVenue(): string;

    abstract protected function resultFromOrder(OrderIntent $intent, array $create, array $order): OrderResult;

    /** How a failed create reads in notes and logs; venues may prefix their id. */
    protected function failureNote(\Throwable $e): string
    {
        return $e->getMessage();
    }

    /** Venue indexing lag is real: a misconfigured 0 must not turn "not visible yet" into "never arrived". */
    protected function graceSeconds(): int
    {
        return max(60, (int) config('desk.live_orders.not_found_grace_seconds', 120));
    }

    /** Spot venues cannot flip short, so a sell may go out while an entry is unresolved; perps override nothing and stay blocked. */
    protected function exitsBypassPendingEntries(): bool
    {
        return false;
    }

    protected function pollAttempts(): int
    {
        return 6;
    }

    /**
     * @param  array<string, mixed>  $context  venue-specific sizing kept on the intent so a later reconcile can rebuild the result
     * @param  \Closure(string): array  $send  performs the create call with the given client_order_id
     */
    protected function submitOrder(string $method, string $deskPid, string $venuePid, string $side, float $requestedUsd, float $decisionPrice, array $context, \Closure $send): OrderResult
    {
        // Bounded to a share of the product lock's TTL: past it the order is reported unknown and reconcile settles it.
        return OrderBudget::within(fn () => $this->submitWithinBudget($method, $deskPid, $venuePid, $side, $requestedUsd, $decisionPrice, $context, $send));
    }

    private function submitWithinBudget(string $method, string $deskPid, string $venuePid, string $side, float $requestedUsd, float $decisionPrice, array $context, \Closure $send): OrderResult
    {
        $intent = OrderIntent::pending()->mode($this->mode())->venue($this->orderVenue())->where('desk_product_id', $deskPid)
            ->when($method === 'sell' && $this->exitsBypassPendingEntries(), fn ($q) => $q->where('method', '!=', 'buy'))
            ->orderBy('id')->first();

        if ($intent !== null) {
            if ($intent->method !== $method) {
                return $this->unknownFor($intent, $requestedUsd, $decisionPrice, "unresolved {$intent->method} order {$intent->client_order_id} on {$deskPid}; not sending {$method} until it is reconciled", null, ['blocked_by_intent' => $intent->client_order_id]);
            }
            $size = (float) ($context['size'] ?? 0);
            $pendingSize = (float) ($intent->context['size'] ?? 0);
            if (abs($size - $pendingSize) > 1e-6 * max(1.0, abs($pendingSize))) {
                return $this->unknownFor($intent, $requestedUsd, $decisionPrice, "unresolved {$method} order {$intent->client_order_id} was for a different size ({$pendingSize}, now {$size}); its own outcome is booked by reconcile, not handed to this call");
            }
            $settled = $this->reconcilePending($intent, $requestedUsd, $decisionPrice);
            if ($settled !== null) {
                return $settled;
            }
            // Looked up cleanly, nothing there, grace elapsed: safe to send again under the same identity.
            $intent->forceFill(['requested_usd' => $requestedUsd, 'decision_price' => $decisionPrice, 'context' => $context])->save();
        } else {
            $intent = OrderIntent::create([
                'mode' => $this->mode(), 'venue' => $this->orderVenue(), 'sent_at' => now(), 'desk_product_id' => $deskPid, 'venue_product_id' => $venuePid,
                'method' => $method, 'side' => $side, 'client_order_id' => str_replace('-', '', (string) Str::uuid()),
                'requested_usd' => $requestedUsd, 'decision_price' => $decisionPrice, 'context' => $context,
            ]);
        }

        return $this->sendIntent($intent, $requestedUsd, $decisionPrice, $send);
    }

    private function sendIntent(OrderIntent $intent, float $requestedUsd, float $decisionPrice, \Closure $send): OrderResult
    {
        $intent->forceFill(['sent_at' => now()])->save();
        try {
            $create = $send($intent->client_order_id);
        } catch (\Throwable $e) {
            if ($this->ambiguousFailure($e)) {
                // The venue may have processed it any time up to now: the grace window restarts here.
                $intent->forceFill(['sent_at' => now(), 'note' => 'create outcome unknown: '.$this->failureNote($e)])->save();
                Log::error('live order outcome unknown after a failed create; holding further orders on this product', [
                    'client_order_id' => $intent->client_order_id, 'product' => $intent->desk_product_id, 'error' => $e->getMessage(),
                ]);

                return $this->unknownFor($intent, $requestedUsd, $decisionPrice, 'create outcome unknown: '.$this->failureNote($e));
            }
            $intent->resolve('rejected', $this->failureNote($e));

            return OrderResult::rejected('error', $requestedUsd, $decisionPrice, $this->failureNote($e));
        }

        if (($why = $this->createRejection($create)) !== null) {
            $intent->resolve('rejected', $why);

            return OrderResult::rejected('rejected', $requestedUsd, $decisionPrice, $why);
        }

        $orderId = $this->createdOrderId($create);
        if ($orderId === null) {
            return $this->unknownFor($intent, $requestedUsd, $decisionPrice, 'venue accepted the order without returning an order id', null, ['create' => $create]);
        }
        $intent->forceFill(['venue_order_id' => $orderId])->save();

        $order = $this->recordFromCreate($create);
        $sleepUs = max(0, (int) config('desk.live_orders.poll_sleep_ms', 400)) * 1000;
        for ($i = 0; $i < $this->pollAttempts() && ! OrderBudget::exhausted(); $i++) {
            if ($sleepUs > 0) {
                usleep($sleepUs);
            }
            try {
                $order = $this->fetchOrderRecord($intent);
            } catch (\Throwable) {
                continue;   // keep the last good record and try again
            }
            if ($this->orderTerminal($order)) {
                break;
            }
        }

        return $this->finish($intent, $create, $order, $requestedUsd, $decisionPrice);
    }

    /** Terminal order -> resolve the intent and report it; anything else stays pending as unknown. */
    private function finish(OrderIntent $intent, array $create, ?array $order, float $requestedUsd, float $decisionPrice): OrderResult
    {
        if ($order === null || ! $this->orderTerminal($order)) {
            Log::error('live order readback did not reach a terminal state; holding further orders on this product', [
                'client_order_id' => $intent->client_order_id, 'venue_order_id' => $intent->venue_order_id, 'status' => $order['status'] ?? null,
            ]);

            return $this->unknownFor($intent, $requestedUsd, $decisionPrice, 'order '.($order['status'] ?? 'status unavailable').' after polling; not confirmed', $intent->venue_order_id, ['create' => $create, 'order' => $order ?? []]);
        }

        $result = $this->resultFromOrder($intent, $create, $order);
        $intent->resolve($result->filledQty > 0 ? 'filled' : 'rejected');

        return $result;
    }

    /**
     * Same-method call against a pending intent: look the order up. Returns the outcome (or an unknown)
     * to hand the caller, or null when the venue provably never got it and the grace window has passed.
     */
    private function reconcilePending(OrderIntent $intent, float $requestedUsd, float $decisionPrice): ?OrderResult
    {
        try {
            $order = $this->lookupOrder($intent);
        } catch (\Throwable $e) {
            return $this->unknownFor($intent, $requestedUsd, $decisionPrice, "cannot verify order {$intent->client_order_id}: ".$e->getMessage());
        }

        if ($order !== null) {
            return $this->finish($intent, [], $order, $requestedUsd, $decisionPrice);
        }

        // Absent only counts as absent when the lookup finished inside the order budget (the product lock
        // may have expired under a slower one) and the venue's indexing lag has had time to pass.
        $grace = $this->graceSeconds();
        if (OrderBudget::exhausted()) {
            return $this->unknownFor($intent, $requestedUsd, $decisionPrice, "lookup of order {$intent->client_order_id} ran past the order budget; cannot call it absent");
        }
        if ($intent->secondsSinceSent() < $grace) {
            return $this->unknownFor($intent, $requestedUsd, $decisionPrice, "order {$intent->client_order_id} not visible at the venue yet ({$intent->secondsSinceSent()}s since sent); waiting out the {$grace}s grace window");
        }

        return null;
    }

    /**
     * Pure venue lookup for the desk's reconcile pass; see ReconcilesOrders. Never writes. Runs inside the
     * order budget, and an answer that arrives after it is discarded: the product lock it ran under may
     * already have expired, so the answer could be stale.
     */
    public function reconcile(OrderIntent $intent): ?OrderResult
    {
        return OrderBudget::within(function () use ($intent) {
            try {
                $order = $this->lookupOrder($intent);
            } catch (\Throwable $e) {
                Log::warning('cannot reconcile live order yet', ['client_order_id' => $intent->client_order_id, 'error' => $e->getMessage()]);

                return null;
            }

            return ! OrderBudget::exhausted() && $order !== null && $this->orderTerminal($order) ? $this->resultFromOrder($intent, [], $order) : null;
        });
    }

    /** "Provably absent": the lookup succeeded, in time, and found nothing after the grace window. Anything else is unknown. */
    public function confirmedAbsent(OrderIntent $intent): bool
    {
        return OrderBudget::within(function () use ($intent) {
            try {
                $order = $this->lookupOrder($intent);
            } catch (\Throwable) {
                return false;
            }

            return $order === null
                && ! OrderBudget::exhausted()
                && $intent->secondsSinceSent() >= $this->graceSeconds();
        });
    }

    private function unknownFor(OrderIntent $intent, float $requestedUsd, float $decisionPrice, string $note, ?string $venueOrderId = null, array $raw = []): OrderResult
    {
        return OrderResult::unknown($requestedUsd, $decisionPrice, 'UNRESOLVED: '.$note, $venueOrderId ?? $intent->venue_order_id, ['client_order_id' => $intent->client_order_id, ...$raw]);
    }
}
