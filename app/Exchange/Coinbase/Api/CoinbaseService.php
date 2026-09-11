<?php

declare(strict_types=1);

namespace App\Exchange\Coinbase\Api;

use App\Models\CoinbaseAccount;
use Illuminate\Support\Str;

/** Authenticated Advanced Trade facade: accounts, orders, fills, fees. */
class CoinbaseService
{
    public function __construct(private CoinbaseHttpClient $client) {}

    // ---- accounts -------------------------------------------------------

    public function listAccounts(CoinbaseAccount $account, int $limit = 250, ?string $cursor = null): array
    {
        return $this->client->get($account, '/accounts', array_filter(['limit' => $limit, 'cursor' => $cursor]));
    }

    /** Available balance for a currency across all accounts (e.g. USD, USDC). */
    public function availableBalance(CoinbaseAccount $account, string $currency): float
    {
        $total = 0.0;
        $cursor = null;
        do {
            $page = $this->listAccounts($account, 250, $cursor);
            foreach ($page['accounts'] ?? [] as $acct) {
                if (strtoupper($acct['currency'] ?? '') === strtoupper($currency)) {
                    $total += (float) ($acct['available_balance']['value'] ?? 0);
                }
            }
            $cursor = ($page['has_next'] ?? false) ? ($page['cursor'] ?? null) : null;
        } while ($cursor);

        return $total;
    }

    /** All non-zero balances keyed by currency. */
    public function balances(CoinbaseAccount $account): array
    {
        $out = [];
        $cursor = null;
        do {
            $page = $this->listAccounts($account, 250, $cursor);
            foreach ($page['accounts'] ?? [] as $acct) {
                $v = (float) ($acct['available_balance']['value'] ?? 0);
                if ($v > 0) {
                    $out[strtoupper($acct['currency'])] = ($out[strtoupper($acct['currency'])] ?? 0) + $v;
                }
            }
            $cursor = ($page['has_next'] ?? false) ? ($page['cursor'] ?? null) : null;
        } while ($cursor);

        return $out;
    }

    // ---- orders ---------------------------------------------------------

    /** Market BUY spending $quoteUsd of quote currency. */
    public function marketBuy(CoinbaseAccount $account, string $productId, float $quoteUsd, ?string $clientOrderId = null): array
    {
        return $this->createOrder($account, [
            'client_order_id' => $clientOrderId ?? (string) Str::uuid(),
            'product_id' => $productId,
            'side' => 'BUY',
            'order_configuration' => [
                'market_market_ioc' => ['quote_size' => $this->fmt($quoteUsd, 2)],
            ],
        ]);
    }

    /** Market SELL of $baseQty base units. */
    public function marketSell(CoinbaseAccount $account, string $productId, float $baseQty, int $precision = 8, ?string $clientOrderId = null): array
    {
        return $this->createOrder($account, [
            'client_order_id' => $clientOrderId ?? (string) Str::uuid(),
            'product_id' => $productId,
            'side' => 'SELL',
            'order_configuration' => [
                'market_market_ioc' => ['base_size' => $this->fmt($baseQty, $precision)],
            ],
        ]);
    }

    /** Futures/perps: market order in whole contracts (base_size = contracts). docs: rest-api/orders/create-order */
    public function marketContracts(CoinbaseAccount $account, string $productId, string $side, int $contracts, ?string $clientOrderId = null): array
    {
        return $this->createOrder($account, [
            "client_order_id" => $clientOrderId ?? (string) Str::uuid(),
            "product_id" => $productId,
            "side" => $side,
            "order_configuration" => [
                "market_market_ioc" => ["base_size" => (string) $contracts],
            ],
        ]);
    }

    /** Coinbase Financial Markets (US futures) balance summary. docs: rest-api/futures/get-futures-balance-summary */
    public function futuresBalance(CoinbaseAccount $account): array
    {
        return $this->client->get($account, "/cfm/balance_summary")["balance_summary"] ?? [];
    }

    /** Open futures positions. docs: rest-api/futures/list-futures-positions */
    public function futuresPositions(CoinbaseAccount $account): array
    {
        return $this->client->get($account, "/cfm/positions")["positions"] ?? [];
    }

    /** Current intraday/overnight/weekend/transition margin window. docs: rest-api/cfm/get-current-margin-window */
    public function marginWindow(CoinbaseAccount $account): array
    {
        return $this->client->get($account, '/cfm/intraday/current_margin_window', [
            'margin_profile_type' => (string) config('desk.perps.margin_profile_type', 'MARGIN_PROFILE_TYPE_RETAIL_REGULAR'),
        ])['margin_window'] ?? [];
    }

    public function createOrder(CoinbaseAccount $account, array $order): array
    {
        return $this->client->post($account, '/orders', $order);
    }

    public function previewOrder(CoinbaseAccount $account, array $order): array
    {
        return $this->client->post($account, '/orders/preview', $order);
    }

    public function cancelOrders(CoinbaseAccount $account, array $orderIds): array
    {
        return $this->client->post($account, '/orders/batch_cancel', ['order_ids' => $orderIds]);
    }

    public function getOrder(CoinbaseAccount $account, string $orderId): array
    {
        return $this->client->get($account, "/orders/historical/{$orderId}");
    }

    public function listOrders(CoinbaseAccount $account, array $filters = []): array
    {
        return $this->client->get($account, '/orders/historical/batch', $filters);
    }

    public function listFills(CoinbaseAccount $account, array $filters = []): array
    {
        return $this->client->get($account, '/orders/historical/fills', $filters);
    }

    // ---- misc -----------------------------------------------------------

    public function transactionSummary(CoinbaseAccount $account): array
    {
        return $this->client->get($account, '/transaction_summary');
    }

    public function keyPermissions(CoinbaseAccount $account): array
    {
        return $this->client->get($account, '/key_permissions');
    }

    public function listPortfolios(CoinbaseAccount $account): array
    {
        return $this->client->get($account, '/portfolios');
    }

    private function fmt(float $n, int $precision): string
    {
        return rtrim(rtrim(number_format($n, $precision, '.', ''), '0'), '.') ?: '0';
    }
}
