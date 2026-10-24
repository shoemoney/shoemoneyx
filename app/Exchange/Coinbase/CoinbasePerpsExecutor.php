<?php

declare(strict_types=1);

namespace App\Exchange\Coinbase;

use App\Desk\Execution\Executor;
use App\Desk\Execution\OrderPlan;
use App\Desk\Execution\OrderResult;
use App\Desk\Execution\Perps;
use App\Desk\Execution\PerpsGate;
use App\Desk\Execution\PerpsSession;
use App\Desk\Execution\ReconcilesOrders;
use App\Desk\Reporter;
use App\Exchange\Coinbase\Api\CoinbaseApiException;
use App\Exchange\Coinbase\Api\CoinbaseService;
use App\Exchange\Coinbase\Concerns\SubmitsCoinbaseOrders;
use App\Models\CoinbaseAccount;
use App\Models\OrderIntent;
use Illuminate\Http\Client\ConnectionException;

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
class CoinbasePerpsExecutor implements Executor, ReconcilesOrders
{
    use SubmitsCoinbaseOrders;

    private ?PerpsSession $cachedSession = null;

    private float $cachedSessionAt = 0.0;

    public function __construct(private CoinbaseService $coinbase) {}

    public function orderVenue(): string
    {
        return 'coinbase_perps';
    }

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

        $account = $this->account();
        $contracts = $plan->contracts;

        $exitGuard = fn (): int => $this->exitContracts($account, $productId, $spotPid, $side, $short, $contracts);
        $guardAtSend = false;

        if (! $checkMargin) {
            // An exit is a plain market order, so it must never exceed what the venue actually holds on
            // the side being closed: a flat or opposite-side venue turns it into a fresh opposite position.
            // A pending intent for this product may be an exit that already filled (venue now flat, only
            // the readback was lost): submitOrder must reconcile it first, so the check moves to the
            // moment a (re)send would actually leave the box.
            $guardAtSend = OrderIntent::pending()->mode($this->mode())->venue($this->orderVenue())->where('desk_product_id', $spotPid)->exists();
            if (! $guardAtSend) {
                try {
                    $contracts = $exitGuard();
                } catch (CoinbaseApiException $e) {
                    return OrderResult::rejected(($e->body['guard'] ?? null) === 'flat' ? 'rejected' : 'error', $requested, $decisionPrice, $e->getMessage());
                }
            }
        }

        $method = match (true) {
            $short && $side === 'SELL' => 'open_short',
            $short => 'cover_short',
            $side === 'BUY' => 'buy',
            default => 'sell',
        };

        try {
            return $this->submitOrder($method, $spotPid, $productId, $side, $requested, $decisionPrice,
                ['spot_pid' => $spotPid, 'short' => $short, 'contracts' => $contracts, 'size' => $contracts],
                fn (string $clientOrderId) => $this->coinbase->marketContracts($account, $productId, $side, $guardAtSend ? $exitGuard() : $contracts, $clientOrderId));
        } finally {
            // The fill changed margin; the next entry in this cycle must not size on the pre-fill balance.
            $this->cachedSession = null;
        }
    }

    /**
     * Contracts an exit may send: the planned size capped at the venue's same-side holding. Throws a
     * CoinbaseApiException: 400 when the venue is flat/opposite (guard=flat, pages; definitive, nothing sent),
     * 503 when the read itself failed (guard=read). 503 is deliberately ambiguous to the idempotency trait, so
     * a deferred check on a pending intent leaves it pending for reconcile instead of resolving it rejected.
     */
    private function exitContracts(CoinbaseAccount $account, string $productId, string $spotPid, string $side, bool $short, int $planned): int
    {
        try {
            $held = $this->venueContracts($account, $productId, $short ? 'SHORT' : 'LONG');
        } catch (CoinbaseApiException|ConnectionException $e) {
            throw new CoinbaseApiException('venue position check failed: '.$e->getMessage(), 503, ['guard' => 'read']);
        }

        if ($held < 1) {
            $note = 'no '.($short ? 'short' : 'long')." position on the venue for {$productId}; refusing to send {$side}";
            app(Reporter::class)->error('PERPS', "{$spotPid}: {$note} (desk row still open, needs an operator)", ['product_id' => $productId, 'side' => $side, 'contracts' => $planned]);

            throw new CoinbaseApiException($note, 400, ['guard' => 'flat']);
        }

        return min($planned, $held);
    }

    /** Whole contracts the venue holds in $productId on $side (LONG|SHORT); docs: futures/list-futures-positions. */
    private function venueContracts(CoinbaseAccount $account, string $productId, string $side): int
    {
        foreach ($this->coinbase->futuresPositions($account) as $position) {
            if (($position['product_id'] ?? null) === $productId && ($position['side'] ?? null) === $side) {
                return (int) floor((float) ($position['number_of_contracts'] ?? 0) + 1e-9);
            }
        }

        return 0;
    }

    protected function pollAttempts(): int
    {
        return 8;
    }

    /** The venue's own order record, turned into what the desk books. Called only for a terminal order. */
    protected function resultFromOrder(OrderIntent $intent, array $create, array $order): OrderResult
    {
        $spotPid = $intent->desk_product_id;
        $side = $intent->side;
        $short = (bool) ($intent->context['short'] ?? false);
        $contracts = (int) ($intent->context['contracts'] ?? 0);
        $requestedUsd = (float) $intent->requested_usd;
        $decisionPrice = (float) $intent->decision_price;
        $orderId = $intent->venue_order_id ?? ($order['order_id'] ?? null);

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
            raw: ['create' => $create, 'order' => $order, 'contracts' => $contracts, 'client_order_id' => $intent->client_order_id],
            note: $status === 'rejected' ? ('order '.($order['status'] ?? 'unknown')) : null,
            basisRecovered: $basisRecovered,
        );
    }
}
