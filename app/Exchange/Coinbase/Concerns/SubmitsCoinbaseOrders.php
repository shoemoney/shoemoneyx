<?php

declare(strict_types=1);

namespace App\Exchange\Coinbase\Concerns;

use App\Desk\Execution\Concerns\SubmitsOrdersIdempotently;
use App\Exchange\Coinbase\Api\CoinbaseApiException;
use App\Models\OrderIntent;

/**
 * Coinbase Advanced Trade wiring for SubmitsOrdersIdempotently, shared by the spot and perps executors.
 * Expects $this->coinbase (CoinbaseService) and $this->account().
 */
trait SubmitsCoinbaseOrders
{
    use SubmitsOrdersIdempotently;

    protected function ambiguousFailure(\Throwable $e): bool
    {
        // A 4xx (bad size, insufficient funds, auth) means the venue looked at the order and said no.
        // Transport errors, timeouts and 5xx leave it unknowable whether the order was accepted.
        return ! ($e instanceof CoinbaseApiException) || $e->status >= 500 || $e->status === 408 || $e->status === 0;
    }

    protected function createRejection(array $create): ?string
    {
        if ($create['success'] ?? false) {
            return null;
        }
        $err = $create['error_response'] ?? [];
        // A duplicate client_order_id means the ORIGINAL order exists; that is a lookup, not a refusal.
        if (stripos((string) ($err['error'] ?? ''), 'DUPLICATE') !== false) {
            return null;
        }

        return ($err['error'] ?? 'REJECTED').': '.($err['message'] ?? $err['preview_failure_reason'] ?? '');
    }

    protected function createdOrderId(array $create): ?string
    {
        $id = $create['success_response']['order_id'] ?? null;

        return $id !== null && $id !== '' ? (string) $id : null;
    }

    protected function lookupOrder(OrderIntent $intent): ?array
    {
        if ($intent->venue_order_id) {
            return $this->fetchOrderRecord($intent);
        }

        return $this->coinbase->findOrderByClientId($this->account(), $intent->venue_product_id, $intent->client_order_id, $intent->created_at);
    }

    protected function fetchOrderRecord(OrderIntent $intent): array
    {
        $order = $this->coinbase->getOrder($this->account(), (string) $intent->venue_order_id)['order'] ?? null;
        if (! is_array($order) || $order === []) {
            throw new CoinbaseApiException("empty order record for {$intent->venue_order_id}");
        }

        return $order;
    }

    protected function orderTerminal(array $order): bool
    {
        return in_array($order['status'] ?? '', ['FILLED', 'CANCELLED', 'EXPIRED', 'FAILED'], true);
    }
}
