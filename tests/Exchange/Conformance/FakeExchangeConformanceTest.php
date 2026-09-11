<?php

declare(strict_types=1);

namespace Tests\Exchange\Conformance;

use App\Desk\Execution\Executor;
use App\Desk\Execution\OrderResult;
use App\Exchange\Contracts\Exchange;
use Tests\Exchange\FakeExchange;
use Tests\Exchange\FakeMarketData;

/**
 * Proves the conformance kit itself against FakeExchange/FakeMarketData: this suite must pass,
 * since it is the reference implementation every real adapter is measured against.
 */
final class FakeExchangeConformanceTest extends ExchangeConformanceTestCase
{
    private const PRODUCT_ID = 'FAKE-USD';

    private ?FakeExchange $fakeExchange = null;

    protected function exchange(): Exchange
    {
        if ($this->fakeExchange !== null) {
            return $this->fakeExchange;
        }

        $marketData = new FakeMarketData;

        $marketData->productRows = [
            ['product_id' => self::PRODUCT_ID, 'base_currency' => 'FAKE', 'quote_currency' => 'USD'],
            ['product_id' => 'OTHER-USD', 'base_currency' => 'OTHER', 'quote_currency' => 'USD'],
        ];

        $marketData->candleRows = [
            ['start' => 60, 'open' => 100.0, 'high' => 101.0, 'low' => 99.5, 'close' => 100.5, 'volume' => 10.0],
            ['start' => 120, 'open' => 100.5, 'high' => 102.0, 'low' => 100.0, 'close' => 101.5, 'volume' => 12.0],
            ['start' => 180, 'open' => 101.5, 'high' => 101.8, 'low' => 100.9, 'close' => 101.0, 'volume' => 8.0],
        ];

        $marketData->tickerData = [
            'best_bid' => 100.9,
            'best_ask' => 101.0,
            'trades' => [
                ['price' => 100.9, 'size' => 0.5, 'side' => 'buy', 'time' => 200],
                ['price' => 101.0, 'size' => 0.2, 'side' => 'sell', 'time' => 205],
            ],
            'source' => 'fake',
            'ts' => 205,
        ];

        $marketData->tradeRows = [
            ['trade_id' => 't1', 'time' => 100, 'time_ms' => 100_000, 'price' => 100.1, 'size' => 0.3, 'side' => 'buy'],
            ['trade_id' => 't2', 'time' => 101, 'time_ms' => 101_000, 'price' => 100.2, 'size' => 0.4, 'side' => 'sell'],
        ];

        $marketData->bookData = [
            'bids' => [[100.9, 1.0], [100.8, 2.0], [100.5, 5.0]],
            'asks' => [[101.0, 1.5], [101.2, 3.0], [101.5, 4.0]],
            'ts' => 205,
        ];

        $marketData->priceValue = 100.95;
        $marketData->isHealthy = true;

        $executor = $this->fakeExecutor();

        return $this->fakeExchange = new FakeExchange(
            exchangeId: 'fake',
            marketData: $marketData,
            liveExecutor: $executor,
            perpsExecutor: $executor,
        );
    }

    protected function sampleProductId(): string
    {
        return self::PRODUCT_ID;
    }

    private function fakeExecutor(): Executor
    {
        return new class implements Executor
        {
            public function mode(): string
            {
                return 'paper';
            }

            public function buy(string $productId, float $usd, float $decisionPrice): OrderResult
            {
                throw new \RuntimeException('conformance kit must never place orders');
            }

            public function sell(string $productId, float $qty, float $decisionPrice, float $entryUsdShare = 0.0, bool $maker = false): OrderResult
            {
                throw new \RuntimeException('conformance kit must never place orders');
            }

            public function openShort(string $productId, float $usd, float $decisionPrice): OrderResult
            {
                throw new \RuntimeException('conformance kit must never place orders');
            }

            public function coverShort(string $productId, float $qty, float $decisionPrice, float $entryUsdShare, bool $maker = false): OrderResult
            {
                throw new \RuntimeException('conformance kit must never place orders');
            }

            public function cash(): float
            {
                return 0.0;
            }
        };
    }
}
