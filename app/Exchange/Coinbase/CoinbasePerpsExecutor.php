<?php

declare(strict_types=1);

namespace App\Exchange\Coinbase;

use App\Desk\Execution\Executor;
use App\Desk\Execution\OrderPlan;
use App\Desk\Execution\OrderResult;
use App\Desk\Execution\Perps;
use App\Desk\Execution\PerpsGate;
use App\Desk\Execution\PerpsSession;
use App\Exchange\Coinbase\Api\CoinbaseApiException;
use App\Exchange\Coinbase\Api\CoinbaseService;
use App\Models\CoinbaseAccount;

/**
 * LIVE execution on Coinbase US perpetual futures (Coinbase Financial Markets, venue "cde").
 *
 * The desk speaks spot symbols (BTC-USD); this executor maps them to the perp (BIP-20DEC30-CDE),
 * turns dollars into whole contracts, and reads every fill back from the order record.
 * Quantities returned to the desk are BASE units (contracts × contract_size) so PnL math is
 * identical to spot. Longs and shorts are both plain market IOC orders: BUY opens/adds a long or
 * covers a short, SELL closes/trims a long or opens/adds a short — the venue nets per product.
 *
 * docs/COINBASE_DOCS.md → orders/create-order (market_market_ioc.base_size = contracts),
 * futures/get-futures-balance-summary, futures/list-futures-positions.
 */
class CoinbasePerpsExecutor implements Executor
{
    private ?PerpsSession $cachedSession = null;

    private float $cachedSessionAt = 0.0;

    public function __construct(private CoinbaseService $coinbase) {}

    public function mode(): string
    {
        return 'live';
    }

    /** Balance summary + margin window, cached 10s on the instance (one cycle calls send() many times). */
    public function session(): PerpsSession
    {
        $now = microtime(true);
        if ($this->cachedSession !== null && $now - $this->cachedSessionAt < 10.0) {
            return $this->cachedSession;
        }

        $balance = $this->coinbase->futuresBalance($this->account());

        try {
            $window = $this->coinbase->marginWindow($this->account());
        } catch (CoinbaseApiException) {
            $window = [];
        }

        $this->cachedSession = PerpsSession::fromApi($balance, $window);
        $this->cachedSessionAt = $now;

        return $this->cachedSession;
    }

    private function account(): CoinbaseAccount
    {
        return CoinbaseAccount::active() ?? throw new \RuntimeException('No active Coinbase account. Run: php artisan coinbase:account');
    }

    /** Cash = what CFM will let us margin with (USD + USDC swept from spot). */
    public function cash(): float
    {
        $b = $this->coinbase->futuresBalance($this->account());

        return (float) ($b['futures_buying_power']['value'] ?? 0);
    }

    public function buy(string $productId, float $usd, float $decisionPrice): OrderResult
    {
        return $this->send($productId, 'BUY', $usd, null, $decisionPrice, checkMargin: true);
    }

    public function sell(string $productId, float $qty, float $decisionPrice, float $entryUsdShare = 0.0, bool $maker = false): OrderResult
    {
        return $this->send($productId, 'SELL', null, $qty, $decisionPrice, checkMargin: false);
    }

    public function openShort(string $productId, float $usd, float $decisionPrice): OrderResult
    {
        return $this->send($productId, 'SELL', $usd, null, $decisionPrice, checkMargin: true, short: true);
    }

    public function coverShort(string $productId, float $qty, float $decisionPrice, float $entryUsdShare, bool $maker = false): OrderResult
    {
        return $this->send($productId, 'BUY', null, $qty, $decisionPrice, checkMargin: false, short: true);
    }

    /**
     * The pre-send half of send(): map the spot symbol, size contracts, and (when $checkMargin) run
     * the margin gate — everything send() needs to decide before it would touch the order endpoint.
     * Never calls marketContracts() or any other order endpoint; the only network call it may trigger
     * is session() (balance + margin window GETs), and only when $checkMargin is true.
     */
    public function plan(string $spotPid, string $side, ?float $usd, ?float $qty, float $decisionPrice, bool $checkMargin): OrderPlan
    {
        $spec = Perps::spec($spotPid);
        if ($spec === null) {
            return new OrderPlan($spotPid, null, $side, 0, 0.0, null, "no perps.map entry for {$spotPid}");
        }

        $contracts = $usd !== null ? Perps::contractsFor($spotPid, $usd, $decisionPrice) : Perps::contractsForQty($spotPid, (float) $qty);
        if ($contracts < 1) {
            return new OrderPlan($spotPid, $spec['product_id'], $side, 0, 0.0, null, 'under one contract');
        }

        $notional = $contracts * $spec['contract_size'] * $decisionPrice;

        if (! $checkMargin) {
            return new OrderPlan($spotPid, $spec['product_id'], $side, $contracts, $notional, null, null);
        }

        try {
            $session = $this->session();
        } catch (CoinbaseApiException $e) {
            return new OrderPlan($spotPid, $spec['product_id'], $side, $contracts, $notional, null, 'margin check failed: '.$e->getMessage(), 'error');
        }

        $gate = PerpsGate::newRisk($session, now()->toDateTimeImmutable(), $notional, config('desk.perps'));

        return new OrderPlan($spotPid, $spec['product_id'], $side, $contracts, $notional, $gate, $gate->allowed ? null : (string) $gate->reason);
    }

    private function send(string $spotPid, string $side, ?float $usd, ?float $qty, float $decisionPrice, bool $checkMargin, bool $short = false): OrderResult
    {
        $requested = $usd ?? ($qty ?? 0) * $decisionPrice;
        $plan = $this->plan($spotPid, $side, $usd, $qty, $decisionPrice, $checkMargin);

        if ($plan->rejection !== null) {
            return OrderResult::rejected($plan->rejectionStatus, $requested, $decisionPrice, $plan->rejection);
        }

        $productId = $plan->perpProductId ?? throw new \LogicException('OrderPlan without a rejection must have a perp product id');

        try {
            $resp = $this->coinbase->marketContracts($this->account(), $productId, $side, $plan->contracts);
        } catch (CoinbaseApiException $e) {
            return OrderResult::rejected('error', $requested, $decisionPrice, $e->getMessage());
        }

        return $this->settle($resp, $spotPid, $side, $short, $plan->contracts, $requested, $decisionPrice);
    }

    private function settle(array $resp, string $spotPid, string $side, bool $short, int $contracts, float $requestedUsd, float $decisionPrice): OrderResult
    {
        if (! ($resp['success'] ?? false)) {
            $err = $resp['error_response'] ?? [];

            return OrderResult::rejected('rejected', $requestedUsd, $decisionPrice, ($err['error'] ?? 'REJECTED').': '.($err['message'] ?? $err['preview_failure_reason'] ?? ''));
        }
        $orderId = $resp['success_response']['order_id'] ?? null;
        $order = [];
        for ($i = 0; $i < 8 && $orderId; $i++) {
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

        $filledContracts = (float) ($order['filled_size'] ?? 0);
        $filledQty = Perps::qtyFor($spotPid, (int) round($filledContracts));
        $fee = (float) ($order['total_fees'] ?? 0);
        $avgReported = (float) ($order['average_filled_price'] ?? 0);
        $gross = $filledQty * $avgReported;
        // Same degraded-poll shape CoinbaseExecutor's spot path defends against: a real fill with
        // average_filled_price missing or 0 (round-5 review — this venue never got round 4's fix,
        // so OrderResult::ok() refused every such fill and Desk::close()/trim() left the position
        // open while the venue had already sold it).
        [$avg, $gross, $basisRecovered] = OrderResult::recoverFillBasis($filledQty, $avgReported > 0 ? $avgReported : null, $gross, $decisionPrice, 'CoinbasePerpsExecutor', $orderId, [
            'average_filled_price' => $order['average_filled_price'] ?? null,
        ]);
        $status = $filledContracts > 0 ? 'filled' : 'rejected';

        // What the desk books:
        //   long entry  (BUY)  → cost incl. fee        short open  (SELL) → gross proceeds (fee separate)
        //   long exit   (SELL) → proceeds net of fee   short cover (BUY)  → cost incl. fee
        $filledUsd = match (true) {
            ! $short && $side === 'BUY' => $gross + $fee,
            ! $short && $side === 'SELL' => $gross - $fee,
            $short && $side === 'SELL' => $gross,
            default => $gross + $fee,
        };

        return new OrderResult(
            status: $status,
            requestedUsd: $requestedUsd,
            filledUsd: $filledUsd,
            filledQty: $filledQty,
            decisionPrice: $decisionPrice,
            fillPrice: $avg,
            feeUsd: $fee,
            partial: $filledContracts > 0 && $filledContracts < $contracts,
            venueOrderId: $orderId,
            raw: ['create' => $resp, 'order' => $order, 'contracts' => $contracts],
            note: $status === 'rejected' ? ('order '.($order['status'] ?? 'unknown')) : null,
            basisRecovered: $basisRecovered,
        );
    }
}
