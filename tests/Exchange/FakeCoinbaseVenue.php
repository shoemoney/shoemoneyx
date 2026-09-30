<?php

declare(strict_types=1);

namespace Tests\Exchange;

use App\Exchange\Coinbase\Api\CoinbaseApiException;
use App\Exchange\Coinbase\Api\CoinbaseService;
use App\Models\CoinbaseAccount;
use Illuminate\Http\Client\ConnectionException;

/**
 * A fake Coinbase venue behind the real CoinbaseService surface. Like the real venue it dedups on
 * client_order_id, and it can be told to fail the way the network does: time out AFTER the order
 * landed (the dangerous case), time out before it did, fail readbacks, fail order-history listing.
 */
class FakeCoinbaseVenue extends CoinbaseService
{
    /** @var array<string, array<string, mixed>> client_order_id => the venue's order record */
    public array $orders = [];

    /** @var array<int, array{client_order_id: string, side: string, product: string}> every create attempt, landed or not */
    public array $attempts = [];

    public bool $timeoutAfterPlacing = false;

    public bool $timeoutBeforePlacing = false;

    public ?int $failCreateWithStatus = null;

    public bool $readbackFails = false;

    public bool $listFails = false;

    public string $orderStatus = 'FILLED';

    public float $price = 100.0;

    public function __construct() {}

    public function marketBuy(CoinbaseAccount $account, string $productId, float $quoteUsd, ?string $clientOrderId = null): array
    {
        return $this->place($productId, 'BUY', $quoteUsd / $this->price, $clientOrderId, $this->price);
    }

    public function marketSell(CoinbaseAccount $account, string $productId, float $baseQty, int $precision = 8, ?string $clientOrderId = null): array
    {
        return $this->place($productId, 'SELL', $baseQty, $clientOrderId, $this->price);
    }

    public function marketContracts(CoinbaseAccount $account, string $productId, string $side, int $contracts, ?string $clientOrderId = null): array
    {
        return $this->place($productId, $side, (float) $contracts, $clientOrderId, $this->price);
    }

    public function getOrder(CoinbaseAccount $account, string $orderId): array
    {
        if ($this->readbackFails) {
            throw new ConnectionException('readback timed out');
        }
        foreach ($this->orders as $order) {
            if ($order['order_id'] === $orderId) {
                return ['order' => $order];
            }
        }

        throw new CoinbaseApiException('NOT_FOUND', 404);
    }

    public function findOrderByClientId(CoinbaseAccount $account, string $productId, string $clientOrderId, \DateTimeInterface $since): ?array
    {
        if ($this->listFails) {
            throw new ConnectionException('order history timed out');
        }

        return $this->orders[$clientOrderId] ?? null;
    }

    public function landedCount(): int
    {
        return count($this->orders);
    }

    private function place(string $productId, string $side, float $size, ?string $clientOrderId, float $price): array
    {
        $this->attempts[] = ['client_order_id' => (string) $clientOrderId, 'side' => $side, 'product' => $productId];

        if ($this->timeoutBeforePlacing) {
            throw new ConnectionException('connect timed out');
        }
        if ($this->failCreateWithStatus !== null) {
            throw new CoinbaseApiException('venue said no', $this->failCreateWithStatus);
        }

        if (! isset($this->orders[(string) $clientOrderId])) {
            $filled = $this->orderStatus === 'FILLED' ? $size : 0.0;
            $this->orders[(string) $clientOrderId] = [
                'order_id' => 'ord-'.(count($this->orders) + 1),
                'client_order_id' => $clientOrderId,
                'product_id' => $productId,
                'side' => $side,
                'status' => $this->orderStatus,
                'filled_size' => (string) $filled,
                'filled_value' => (string) ($filled * $price),
                'average_filled_price' => $filled > 0 ? (string) $price : '0',
                'total_fees' => (string) ($filled * $price * 0.006),
                'order_configuration' => ['market_market_ioc' => ['base_size' => (string) $size]],
            ];
        }

        if ($this->timeoutAfterPlacing) {
            throw new ConnectionException('cURL error 28: operation timed out');
        }

        return ['success' => true, 'success_response' => ['order_id' => $this->orders[(string) $clientOrderId]['order_id']]];
    }
}
