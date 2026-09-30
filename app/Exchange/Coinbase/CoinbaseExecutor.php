<?php

declare(strict_types=1);

namespace App\Exchange\Coinbase;

use App\Desk\Execution\Executor;
use App\Desk\Execution\OrderResult;
use App\Desk\Execution\ReconcilesOrders;
use App\Exchange\Coinbase\Api\CoinbaseService;
use App\Exchange\Coinbase\Concerns\SubmitsCoinbaseOrders;
use App\Models\CoinbaseAccount;
use App\Models\OrderIntent;
use App\Models\Product;

/**
 * LIVE execution through Coinbase Advanced Trade. One venue, one path.
 * Every order is an immediate-or-cancel market order; the fill is read back
 * from the order record so what we store is what actually happened.
 */
class CoinbaseExecutor implements Executor, ReconcilesOrders
{
    use SubmitsCoinbaseOrders;

    public function __construct(private CoinbaseService $coinbase) {}

    public function mode(): string
    {
        return 'live';
    }

    private function account(): CoinbaseAccount
    {
        return CoinbaseAccount::active() ?? throw new \RuntimeException('No active Coinbase account. Run: php artisan coinbase:account');
    }

    public function cash(): float
    {
        $acct = $this->account();
        $total = 0.0;
        foreach (config('desk.universe.quote_currencies', ['USD']) as $ccy) {
            $total += $this->coinbase->availableBalance($acct, $ccy);
        }

        return $total;
    }

    public function buy(string $productId, float $usd, float $decisionPrice): OrderResult
    {
        $account = $this->account();

        return $this->submitOrder('buy', $productId, $productId, 'BUY', $usd, $decisionPrice, [],
            fn (string $clientOrderId) => $this->coinbase->marketBuy($account, $productId, $usd, $clientOrderId));
    }

    public function sell(string $productId, float $qty, float $decisionPrice, float $entryUsdShare = 0.0, bool $maker = false): OrderResult
    {
        $precision = $this->basePrecision($productId);
        $qty = floor($qty * 10 ** $precision) / 10 ** $precision;
        $account = $this->account();

        return $this->submitOrder('sell', $productId, $productId, 'SELL', $qty * $decisionPrice, $decisionPrice, [],
            fn (string $clientOrderId) => $this->coinbase->marketSell($account, $productId, $qty, $precision, $clientOrderId));
    }

    public function openShort(string $productId, float $usd, float $decisionPrice): OrderResult
    {
        return OrderResult::rejected('rejected', $usd, $decisionPrice, 'spot cannot short — enable desk.perps for '.$productId);
    }

    public function coverShort(string $productId, float $qty, float $decisionPrice, float $entryUsdShare, bool $maker = false): OrderResult
    {
        return OrderResult::rejected('rejected', $qty * $decisionPrice, $decisionPrice, 'spot has no short to cover');
    }

    /** The venue's own order record, turned into what the desk books. Called only for a terminal order. */
    protected function resultFromOrder(OrderIntent $intent, array $create, array $order): OrderResult
    {
        $side = $intent->side;
        $requestedUsd = (float) $intent->requested_usd;
        $decisionPrice = (float) $intent->decision_price;
        $orderId = $intent->venue_order_id ?? ($order['order_id'] ?? null);

        $filledQty = (float) ($order['filled_size'] ?? 0);
        $filledValue = (float) ($order['filled_value'] ?? 0);
        $fee = (float) ($order['total_fees'] ?? 0);
        $avgReported = (float) ($order['average_filled_price'] ?? 0);
        $avg = $avgReported > 0 ? $avgReported : ($filledQty > 0 && $filledValue > 0 ? $filledValue / $filledQty : null);
        [$avg, $filledValue, $basisRecovered] = OrderResult::recoverFillBasis($filledQty, $avg, $filledValue, $decisionPrice, 'CoinbaseExecutor', $orderId, [
            'average_filled_price' => $order['average_filled_price'] ?? null,
        ]);
        $status = $filledQty > 0 ? 'filled' : 'rejected';
        $partial = $side === 'BUY'
            ? $filledValue + $fee < $requestedUsd * 0.98
            : (float) ($order['order_configuration']['market_market_ioc']['base_size'] ?? $filledQty) > $filledQty * 1.001;

        return new OrderResult(
            status: $status,
            requestedUsd: $requestedUsd,
            filledUsd: $side === 'BUY' ? $filledValue + $fee : $filledValue - $fee,
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

    private function basePrecision(string $productId): int
    {
        $inc = Product::where('product_id', $productId)->value('base_increment');
        if (! $inc) {
            return 8;
        }
        $s = rtrim(rtrim((string) $inc, '0'), '.');

        return str_contains($s, '.') ? strlen(substr($s, strpos($s, '.') + 1)) : 0;
    }
}
