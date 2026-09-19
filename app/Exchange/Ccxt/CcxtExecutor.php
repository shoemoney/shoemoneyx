<?php

declare(strict_types=1);

namespace App\Exchange\Ccxt;

use App\Desk\Execution\Executor;
use App\Desk\Execution\OrderResult;

/**
 * LIVE market execution through ccxt. Spot only: what gets stored is the order
 * read back from the venue after the fill, never the create response.
 */
class CcxtExecutor implements Executor
{
    /** ccxt statuses that mean the venue is done with the order. */
    private const TERMINAL = ['closed', 'canceled', 'expired', 'rejected'];

    public function __construct(private \ccxt\Exchange $client, private string $ccxtId) {}

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

        try {
            $create = $byCost
                ? $this->client->create_market_buy_order_with_cost($symbol, $usd)
                : $this->client->create_order($symbol, 'market', 'buy', $usd / $decisionPrice);
        } catch (\Throwable $e) {
            return OrderResult::rejected('error', $usd, $decisionPrice, $this->ccxtId.': '.$e->getMessage());
        }

        return $this->settle($create, $symbol, 'BUY', $usd, $decisionPrice, $usd);
    }

    public function sell(string $productId, float $qty, float $decisionPrice, float $entryUsdShare = 0.0, bool $maker = false): OrderResult
    {
        $symbol = Symbols::toCcxt($productId);

        try {
            $create = $this->client->create_order($symbol, 'market', 'sell', $qty);
        } catch (\Throwable $e) {
            return OrderResult::rejected('error', $qty * $decisionPrice, $decisionPrice, $this->ccxtId.': '.$e->getMessage());
        }

        return $this->settle($create, $symbol, 'SELL', $qty * $decisionPrice, $decisionPrice, $qty);
    }

    public function openShort(string $productId, float $usd, float $decisionPrice): OrderResult
    {
        return OrderResult::rejected('rejected', $usd, $decisionPrice, 'ccxt spot adapter cannot short — perps not supported in v1');
    }

    public function coverShort(string $productId, float $qty, float $decisionPrice, float $entryUsdShare, bool $maker = false): OrderResult
    {
        return OrderResult::rejected('rejected', $qty * $decisionPrice, $decisionPrice, 'ccxt spot adapter cannot short — perps not supported in v1');
    }

    /**
     * Market orders settle immediately; poll the order record a few times so the fill we
     * report is the venue's own, then derive everything from that readback.
     *
     * @param  float  $requested  requested USD for a buy, requested base qty for a sell
     */
    private function settle(array $create, string $symbol, string $side, float $requestedUsd, float $decisionPrice, float $requested): OrderResult
    {
        $orderId = isset($create['id']) ? (string) $create['id'] : null;

        // The create response is only the fallback: what gets reported is the order the venue
        // hands back afterwards. Venues without fetchOrder leave us nothing better than create.
        $order = $create;
        $canReadBack = $orderId !== null && ($this->client->has['fetchOrder'] ?? false);

        for ($i = 0; $canReadBack && $i < 6; $i++) {
            usleep(400_000);
            try {
                $order = $this->client->fetch_order($orderId, $symbol);
            } catch (\Throwable) {
                continue;   // Keep the last good record and try again.
            }
            if (in_array((string) ($order['status'] ?? ''), self::TERMINAL, true)) {
                break;
            }
        }

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
            raw: ['create' => $create, 'order' => $order],
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
