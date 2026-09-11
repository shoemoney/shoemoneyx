<?php

declare(strict_types=1);

namespace App\Exchange\Coinbase;

use App\Desk\Execution\Executor;
use App\Desk\Execution\OrderResult;
use App\Exchange\Coinbase\Api\CoinbaseApiException;
use App\Exchange\Coinbase\Api\CoinbaseService;
use App\Models\CoinbaseAccount;
use App\Models\Product;

/**
 * LIVE execution through Coinbase Advanced Trade. One venue, one path.
 * Every order is an immediate-or-cancel market order; the fill is read back
 * from the order record so what we store is what actually happened.
 */
class CoinbaseExecutor implements Executor
{
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
        try {
            $resp = $this->coinbase->marketBuy($this->account(), $productId, $usd);
        } catch (CoinbaseApiException $e) {
            return OrderResult::rejected('error', $usd, $decisionPrice, $e->getMessage());
        }

        return $this->settle($resp, 'BUY', $usd, $decisionPrice);
    }

    public function sell(string $productId, float $qty, float $decisionPrice, float $entryUsdShare = 0.0, bool $maker = false): OrderResult
    {
        $precision = $this->basePrecision($productId);
        $qty = floor($qty * 10 ** $precision) / 10 ** $precision;
        try {
            $resp = $this->coinbase->marketSell($this->account(), $productId, $qty, $precision);
        } catch (CoinbaseApiException $e) {
            return OrderResult::rejected('error', $qty * $decisionPrice, $decisionPrice, $e->getMessage());
        }

        return $this->settle($resp, 'SELL', $qty * $decisionPrice, $decisionPrice);
    }

    public function openShort(string $productId, float $usd, float $decisionPrice): OrderResult
    {
        return OrderResult::rejected('rejected', $usd, $decisionPrice, 'spot cannot short — enable desk.perps for '.$productId);
    }

    public function coverShort(string $productId, float $qty, float $decisionPrice, float $entryUsdShare, bool $maker = false): OrderResult
    {
        return OrderResult::rejected('rejected', $qty * $decisionPrice, $decisionPrice, 'spot has no short to cover');
    }

    private function settle(array $resp, string $side, float $requestedUsd, float $decisionPrice): OrderResult
    {
        if (! ($resp['success'] ?? false)) {
            $err = $resp['error_response'] ?? [];

            return OrderResult::rejected('rejected', $requestedUsd, $decisionPrice, ($err['error'] ?? 'REJECTED').': '.($err['message'] ?? $err['preview_failure_reason'] ?? ''));
        }
        $orderId = $resp['success_response']['order_id'] ?? null;
        $order = [];
        // IOC market orders settle immediately; poll a few times for the fill record.
        for ($i = 0; $i < 6 && $orderId; $i++) {
            usleep(400_000);
            try {
                $order = $this->coinbase->getOrder($this->account(), $orderId)['order'] ?? [];
            } catch (CoinbaseApiException) {
                $order = [];
            }
            if (in_array($order['status'] ?? '', ['FILLED', 'CANCELLED', 'EXPIRED', 'FAILED'], true)) {
                break;
            }
        }

        $filledQty = (float) ($order['filled_size'] ?? 0);
        $filledValue = (float) ($order['filled_value'] ?? 0);
        $fee = (float) ($order['total_fees'] ?? 0);
        $avg = (float) ($order['average_filled_price'] ?? 0) ?: ($filledQty > 0 ? $filledValue / $filledQty : null);
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
            raw: ['create' => $resp, 'order' => $order],
            note: $status === 'rejected' ? ('order '.($order['status'] ?? 'unknown')) : null,
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
