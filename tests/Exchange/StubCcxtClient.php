<?php

declare(strict_types=1);

namespace Tests\Exchange;

/**
 * A real \ccxt\Exchange subclass whose fetch methods return canned rows, so the adapter's
 * mapping can be asserted without a network call or an API key. Rate limiting stays off,
 * so nothing here sleeps.
 */
class StubCcxtClient extends \ccxt\Exchange
{
    /** @var array<string, array<string, mixed>> */
    public array $stubMarkets = [];

    /** @var array<int, array<int, mixed>> */
    public array $stubOhlcv = [];

    /** @var array<string, mixed> */
    public array $stubTicker = [];

    /** @var array<int, array<string, mixed>> */
    public array $stubTrades = [];

    /** @var array<string, mixed> */
    public array $stubBook = [];

    /** @var array<string, mixed> what create_order / create_market_buy_order_with_cost return */
    public array $stubCreate = [];

    /** @var array<int, array<string, mixed>> one entry per fetch_order call, last entry repeats */
    public array $stubOrders = [];

    /** @var array<string, mixed> */
    public array $stubBalance = [];

    /** Thrown by the next create call, so rejection paths can be exercised. */
    public ?\Throwable $createThrows = null;

    /** @var array<int, array<string, mixed>> calls recorded as [method, ...args] */
    public array $calls = [];

    public function __construct($options = [])
    {
        parent::__construct($options);

        // The base class advertises nothing; declare exactly the calls this stub answers,
        // so capability guards in the adapter are exercised the way a real venue exercises them.
        $this->has['fetchOrder'] = true;
        $this->has['fetchTrades'] = true;
        $this->has['fetchStatus'] = false;
    }

    public function load_markets($reload = false, $params = [])
    {
        $this->calls[] = ['load_markets'];

        return $this->stubMarkets;
    }

    public function fetch_ohlcv(string $symbol, string $timeframe = '1m', ?int $since = null, ?int $limit = null, $params = [])
    {
        $this->calls[] = ['fetch_ohlcv', $symbol, $timeframe, $since, $limit];

        return $this->stubOhlcv;
    }

    public function fetch_ticker(string $symbol, $params = [])
    {
        $this->calls[] = ['fetch_ticker', $symbol];

        return $this->stubTicker;
    }

    public function fetch_trades(string $symbol, ?int $since = null, ?int $limit = null, $params = [])
    {
        $this->calls[] = ['fetch_trades', $symbol, $since, $limit];

        return $this->stubTrades;
    }

    public function fetch_order_book(string $symbol, ?int $limit = null, $params = [])
    {
        $this->calls[] = ['fetch_order_book', $symbol, $limit];

        return $this->stubBook;
    }

    public function fetch_time($params = [])
    {
        $this->calls[] = ['fetch_time'];

        return 1_757_000_000_000;
    }

    public function fetch_balance($params = [])
    {
        $this->calls[] = ['fetch_balance'];

        return $this->stubBalance;
    }

    public function create_order(string $symbol, string $type, string $side, float $amount, ?float $price = null, $params = [])
    {
        $this->calls[] = ['create_order', $symbol, $type, $side, $amount];

        return $this->created();
    }

    public function create_market_buy_order_with_cost(string $symbol, float $cost, $params = [])
    {
        $this->calls[] = ['create_market_buy_order_with_cost', $symbol, $cost];

        return $this->created();
    }

    public function fetch_order(string $id, ?string $symbol = null, $params = [])
    {
        $this->calls[] = ['fetch_order', $id, $symbol];
        $nth = count(array_filter($this->calls, fn ($c) => $c[0] === 'fetch_order'));

        return $this->stubOrders[$nth - 1] ?? end($this->stubOrders) ?: [];
    }

    private function created(): array
    {
        if ($this->createThrows !== null) {
            throw $this->createThrows;
        }

        return $this->stubCreate;
    }
}
