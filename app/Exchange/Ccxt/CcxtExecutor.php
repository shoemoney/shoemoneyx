<?php

declare(strict_types=1);

namespace App\Exchange\Ccxt;

use App\Desk\Execution\Concerns\SubmitsOrdersIdempotently;
use App\Desk\Execution\Executor;
use App\Desk\Execution\OrderResult;
use App\Desk\Execution\ReconcilesOrders;
use App\Models\OrderIntent;
use ccxt\ArgumentsRequired;
use ccxt\AuthenticationError;
use ccxt\BadRequest;
use ccxt\Exchange;
use ccxt\InsufficientFunds;
use ccxt\InvalidOrder;
use ccxt\NotSupported;

/**
 * LIVE market execution through ccxt. Spot only: what gets stored is the order
 * read back from the venue after the fill, never the create response.
 */
class CcxtExecutor implements Executor, ReconcilesOrders
{
    use SubmitsOrdersIdempotently;

    /** ccxt statuses that mean the venue is done with the order. */
    private const TERMINAL = ['closed', 'canceled', 'expired', 'rejected'];

    public function __construct(private Exchange $client, private string $ccxtId) {}

    public function mode(): string
    {
        return 'live';
    }

    public function cash(): float
    {
        $free = $this->client->fetch_balance()['free'] ?? [];
        $total = 0.0;
        foreach (config('desk.universe.quote_currencies', ['USD']) as $ccy) {
            $total += (float) ($free[$ccy] ?? 0);
        }

        return $total;
    }

    public function buy(string $productId, float $usd, float $decisionPrice): OrderResult
    {
        $symbol = Symbols::toCcxt($productId);
        $byCost = (bool) ($this->client->has['createMarketBuyOrderWithCost'] ?? false);

        if (! $byCost && $decisionPrice <= 0) {
            return OrderResult::rejected('error', $usd, $decisionPrice, "{$this->ccxtId} sizes market buys in base units and no decision price was given");
        }

        return $this->submitOrder('buy', $productId, $productId, 'BUY', $usd, $decisionPrice, ['requested' => $usd],
            fn (string $clientOrderId) => $byCost
                ? $this->client->create_market_buy_order_with_cost($symbol, $usd, ['clientOrderId' => $clientOrderId])
                : $this->client->create_order($symbol, 'market', 'buy', $usd / $decisionPrice, null, ['clientOrderId' => $clientOrderId]));
    }

    public function sell(string $productId, float $qty, float $decisionPrice, float $entryUsdShare = 0.0, bool $maker = false): OrderResult
    {
        $symbol = Symbols::toCcxt($productId);

        return $this->submitOrder('sell', $productId, $productId, 'SELL', $qty * $decisionPrice, $decisionPrice, ['requested' => $qty],
            fn (string $clientOrderId) => $this->client->create_order($symbol, 'market', 'sell', $qty, null, ['clientOrderId' => $clientOrderId]));
    }

    public function openShort(string $productId, float $usd, float $decisionPrice): OrderResult
    {
        return OrderResult::rejected('rejected', $usd, $decisionPrice, 'ccxt spot adapter cannot short — perps not supported in v1');
    }

    public function coverShort(string $productId, float $qty, float $decisionPrice, float $entryUsdShare, bool $maker = false): OrderResult
    {
        return OrderResult::rejected('rejected', $qty * $decisionPrice, $decisionPrice, 'ccxt spot adapter cannot short — perps not supported in v1');
    }

    /** Only definitive venue refusals are safe to call "not placed"; a timeout or a 5xx may hide a live order. */
    protected function ambiguousFailure(\Throwable $e): bool
    {
        return ! ($e instanceof InvalidOrder || $e instanceof InsufficientFunds || $e instanceof BadRequest
            || $e instanceof AuthenticationError || $e instanceof NotSupported || $e instanceof ArgumentsRequired);
    }

    protected function failureNote(\Throwable $e): string
    {
        return $this->ccxtId.': '.$e->getMessage();
    }

    protected function createRejection(array $create): ?string
    {
        return null;   // ccxt raises on refusals
    }

    protected function createdOrderId(array $create): ?string
    {
        return isset($create['id']) ? (string) $create['id'] : null;
    }

    /** Venues without fetchOrder leave nothing better than the create response. */
    protected function recordFromCreate(array $create): ?array
    {
        return $create;
    }

    protected function pollAttempts(): int
    {
        return ($this->client->has['fetchOrder'] ?? false) ? 6 : 0;
    }

    protected function fetchOrderRecord(OrderIntent $intent): array
    {
        if (! ($this->client->has['fetchOrder'] ?? false)) {
            throw new \RuntimeException("{$this->ccxtId} has no fetchOrder");
        }

        return $this->client->fetch_order((string) $intent->venue_order_id, Symbols::toCcxt($intent->desk_product_id));
    }

    /** By venue id when we have one; otherwise by scanning the venue's order lists for our clientOrderId. */
    protected function lookupOrder(OrderIntent $intent): ?array
    {
        if ($intent->venue_order_id) {
            return $this->fetchOrderRecord($intent);
        }

        $symbol = Symbols::toCcxt($intent->desk_product_id);
        $since = $intent->created_at->getTimestamp() * 1000 - 300_000;
        $lists = array_values(array_filter(
            ['fetchOrders' => 'fetch_orders', 'fetchOpenOrders' => 'fetch_open_orders', 'fetchClosedOrders' => 'fetch_closed_orders'],
            fn ($method, $capability) => (bool) ($this->client->has[$capability] ?? false),
            ARRAY_FILTER_USE_BOTH,
        ));
        if ($lists === []) {
            throw new \RuntimeException("{$this->ccxtId} cannot list orders, so an order without a venue id cannot be looked up by clientOrderId");
        }
        foreach ($lists as $method) {
            foreach ($this->client->{$method}($symbol, $since) as $order) {
                if (($order['clientOrderId'] ?? null) === $intent->client_order_id) {
                    return $order;
                }
            }
        }

        return null;
    }

    protected function orderTerminal(array $order): bool
    {
        return in_array((string) ($order['status'] ?? ''), self::TERMINAL, true);
    }

    /** The venue's own order record, turned into what the desk books. Called only for a terminal order. */
    protected function resultFromOrder(OrderIntent $intent, array $create, array $order): OrderResult
    {
        $side = $intent->side;
        $requestedUsd = (float) $intent->requested_usd;
        $decisionPrice = (float) $intent->decision_price;
        $requested = (float) ($intent->context['requested'] ?? $requestedUsd);
        $orderId = $intent->venue_order_id ?? (isset($order['id']) ? (string) $order['id'] : null);

        $filledQty = (float) ($order['filled'] ?? 0);
        $cost = (float) ($order['cost'] ?? 0);
        $fee = $this->feeOf($order);
        $avg = (float) ($order['average'] ?? 0) ?: ($filledQty > 0 ? $cost / $filledQty : null);
        // Same degraded-poll shape CoinbaseExecutor defends against: a real fill with no usable
        // average (round-5 review — this venue never got round 4's fix at all, so OrderResult::ok()
        // refused every such fill and Desk::close()/trim() left the position open while the venue
        // had already sold it).
        [$avg, $cost, $basisRecovered] = OrderResult::recoverFillBasis($filledQty, $avg, $cost, $decisionPrice, "{$this->ccxtId} (ccxt)", $orderId, [
            'average' => $order['average'] ?? null,
        ]);
        $status = $filledQty > 0 ? 'filled' : 'rejected';
        $partial = $side === 'BUY'
            ? $cost + $fee < $requested * 0.98
            : $filledQty < $requested * 0.98;

        return new OrderResult(
            status: $status,
            requestedUsd: $requestedUsd,
            filledUsd: $side === 'BUY' ? $cost + $fee : $cost - $fee,
            filledQty: $filledQty,
            decisionPrice: $decisionPrice,
            fillPrice: $avg,
            feeUsd: $fee,
            partial: $partial,
            venueOrderId: $orderId,
            raw: ['create' => $create, 'order' => $order, 'client_order_id' => $intent->client_order_id],
            note: $status === 'rejected' ? ('order '.($order['status'] ?? 'unknown')) : null,
            basisRecovered: $basisRecovered,
        );
    }

    /**
     * ccxt fills either 'fee' (one fee) or 'fees' (a list), and venues that fill both put the
     * same charge in each — so the list wins outright rather than being added to the single fee.
     */
    private function feeOf(array $order): float
    {
        if (! empty($order['fees'])) {
            return array_sum(array_map(fn ($f) => (float) ($f['cost'] ?? 0), $order['fees']));
        }

        return (float) ($order['fee']['cost'] ?? 0);
    }
}
