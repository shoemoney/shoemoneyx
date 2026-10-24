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
use ccxt\DuplicateOrderId;
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

        return $this->submitOrder('buy', $productId, $productId, 'BUY', $usd, $decisionPrice, ['requested' => $usd, 'size' => $usd],
            fn (string $clientOrderId) => $byCost
                ? $this->client->create_market_buy_order_with_cost($symbol, $usd, $this->orderParams($clientOrderId))
                : $this->client->create_order($symbol, 'market', 'buy', $usd / $decisionPrice, null, $this->orderParams($clientOrderId)));
    }

    public function sell(string $productId, float $qty, float $decisionPrice, float $entryUsdShare = 0.0, bool $maker = false): OrderResult
    {
        $symbol = Symbols::toCcxt($productId);

        return $this->submitOrder('sell', $productId, $productId, 'SELL', $qty * $decisionPrice, $decisionPrice, ['requested' => $qty, 'size' => $qty],
            fn (string $clientOrderId) => $this->client->create_order($symbol, 'market', 'sell', $qty, null, $this->orderParams($clientOrderId)));
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
        // A duplicate id means the ORIGINAL order exists: look it up, never treat it as a refusal.
        if ($e instanceof DuplicateOrderId) {
            return true;
        }

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

    /** @return array{clientOrderId: int|string} */
    private function orderParams(string $clientOrderId): array
    {
        return ['clientOrderId' => ClientOrderIds::forRequest($this->ccxtId, $clientOrderId)];
    }

    protected function newClientOrderId(): string
    {
        return ClientOrderIds::generate($this->ccxtId);
    }

    protected function exitsBypassPendingEntries(): bool
    {
        return true;
    }

    public function orderVenue(): string
    {
        return 'ccxt:'.$this->ccxtId;
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
        if ($intent->venue_order_id && ($this->client->has['fetchOrder'] ?? false)) {
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
        $coversClosed = false;
        foreach ($lists as $method) {
            $coversClosed = $coversClosed || $method !== 'fetch_open_orders';
            foreach ($this->client->{$method}($symbol, $since) as $order) {
                if ((string) ($order['clientOrderId'] ?? '') === $intent->client_order_id
                    || ($intent->venue_order_id && (string) ($order['id'] ?? '') === $intent->venue_order_id)) {
                    return $order;
                }
            }
        }

        // A filled order is not open, so an open-orders list that lacks it proves nothing.
        if (! $coversClosed) {
            throw new \RuntimeException("{$this->ccxtId} can only list open orders, so a missing order cannot be proven absent");
        }

        return null;
    }

    protected function orderTerminal(array $order): bool
    {
        if (in_array((string) ($order['status'] ?? ''), self::TERMINAL, true)) {
            return true;
        }

        // A venue with no fetchOrder can never show us a later status, so whatever its create response
        // reports as filled is all there will ever be (what this adapter always booked before).
        return ! ($this->client->has['fetchOrder'] ?? false) && (float) ($order['filled'] ?? 0) > 0;
    }

    /** The venue's own order record, turned into what the desk books. Called only for a terminal order. */
    protected function resultFromOrder(OrderIntent $intent, array $create, array $order): OrderResult
    {
        $side = $intent->side;
        $requestedUsd = (float) $intent->requested_usd;
        $decisionPrice = (float) $intent->decision_price;
        $requested = (float) ($intent->context['requested'] ?? $requestedUsd);
        $orderId = $intent->venue_order_id ?? (isset($order['id']) ? (string) $order['id'] : null);

        $raw = [];
        $filledQty = (float) ($order['filled'] ?? 0);
        $cost = (float) ($order['cost'] ?? 0);
        $avg = (float) ($order['average'] ?? 0) ?: ($filledQty > 0 ? $cost / $filledQty : null);
        // Same degraded-poll shape CoinbaseExecutor defends against: a real fill with no usable
        // average (round-5 review — this venue never got round 4's fix at all, so OrderResult::ok()
        // refused every such fill and Desk::close()/trim() left the position open while the venue
        // had already sold it).
        [$avg, $cost, $basisRecovered] = OrderResult::recoverFillBasis($filledQty, $avg, $cost, $decisionPrice, "{$this->ccxtId} (ccxt)", $orderId, [
            'average' => $order['average'] ?? null,
        ]);
        // A fee charged in the base asset (Binance buys) leaves the account holding less than `filled`;
        // booking the gross qty makes the later close sell coins that are not there.
        ['usd' => $fee, 'cashUsd' => $cashFee, 'baseQty' => $baseFee, 'unconverted' => $unconverted] = $this->feesOf($order, $intent->desk_product_id, (float) $avg);
        // Fees may be negative (rebates). The fill price above keeps the gross qty; a sell's base fee is
        // extra inventory the account lost beyond `filled`.
        if ($baseFee !== 0.0) {
            $filledQty = max(0.0, $side === 'BUY' ? $filledQty - $baseFee : $filledQty + $baseFee);
        }
        if ($unconverted !== []) {
            $raw['unconverted_fees'] = $unconverted;
        }
        $status = $filledQty > 0 ? 'filled' : 'rejected';
        $partial = $side === 'BUY'
            ? $cost + $cashFee < $requested * 0.98
            : $filledQty < $requested * 0.98;

        return new OrderResult(
            status: $status,
            requestedUsd: $requestedUsd,
            filledUsd: $side === 'BUY' ? $cost + $cashFee : $cost - $cashFee,
            filledQty: $filledQty,
            decisionPrice: $decisionPrice,
            fillPrice: $avg,
            feeUsd: $fee,
            partial: $partial,
            venueOrderId: $orderId,
            raw: ['create' => $create, 'order' => $order, 'client_order_id' => $intent->client_order_id] + $raw,
            note: $status === 'rejected' ? ('order '.($order['status'] ?? 'unknown')) : $this->unconvertedNote($unconverted),
            basisRecovered: $basisRecovered,
        );
    }

    /**
     * ccxt fills either 'fee' (one fee) or 'fees' (a list), and venues that fill both put the
     * same charge in each, so the list wins outright rather than being added to the single fee.
     * A fee with no currency is a quote-currency fee, which is what this adapter always assumed.
     *
     * usd: every fee valued in USD (informational). cashUsd: the part that came out of quote cash.
     * baseQty: the part charged in the base asset. unconverted: fees in a third currency with no price.
     *
     * @return array{usd: float, cashUsd: float, baseQty: float, unconverted: array<int, array{currency: string, cost: float}>}
     */
    private function feesOf(array $order, string $productId, float $price): array
    {
        $fees = ! empty($order['fees']) ? $order['fees'] : (isset($order['fee']['cost']) ? [$order['fee']] : []);
        [$base, $quote] = array_pad(explode('-', $productId, 2), 2, '');
        $out = ['usd' => 0.0, 'cashUsd' => 0.0, 'baseQty' => 0.0, 'unconverted' => []];

        foreach ($fees as $f) {
            $cost = (float) ($f['cost'] ?? 0);
            $ccy = strtoupper((string) ($f['currency'] ?? ''));
            if ($cost === 0.0) {
                continue;
            }
            if ($ccy === '' || $ccy === strtoupper($quote)) {
                $out['usd'] += $cost;
                $out['cashUsd'] += $cost;
            } elseif ($ccy === strtoupper($base)) {
                $out['baseQty'] += $cost;
                $out['usd'] += $cost * $price;
            } elseif (($rate = $this->rateIn($ccy, $quote)) !== null) {
                $out['usd'] += $cost * $rate;
                $out['cashUsd'] += $cost * $rate;
            } else {
                $out['unconverted'][] = ['currency' => $ccy, 'cost' => $cost];
            }
        }

        return $out;
    }

    private function rateIn(string $currency, string $quote): ?float
    {
        if ($quote === '' || ! ($this->client->has['fetchTicker'] ?? true)) {
            return null;
        }
        try {
            $last = (float) ($this->client->fetch_ticker("{$currency}/{$quote}")['last'] ?? 0);
        } catch (\Throwable) {
            return null;
        }

        return $last > 0 ? $last : null;
    }

    private function unconvertedNote(array $unconverted): ?string
    {
        if ($unconverted === []) {
            return null;
        }

        return "{$this->ccxtId}: fee charged in ".implode(', ', array_column($unconverted, 'currency')).' could not be priced; not booked as USD';
    }
}
