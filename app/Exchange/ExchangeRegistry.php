<?php

declare(strict_types=1);

namespace App\Exchange;

use App\Exchange\Ccxt\CcxtExchange;
use App\Exchange\Contracts\Exchange;

/**
 * Resolves exchange adapters by id, or the configured active one.
 *
 * Two sources: hand-written drivers in config('exchanges.drivers'), and the ccxt ids
 * enabled in config('exchanges.ccxt.enabled'). A native driver always wins over a
 * same-named ccxt id, so enabling 'coinbase' through ccxt cannot shadow the real one.
 */
class ExchangeRegistry
{
    /** @return array<int, string> registered exchange ids */
    public function all(): array
    {
        $native = array_keys((array) config('exchanges.drivers', []));

        return array_values(array_unique(array_merge($native, $this->ccxtIds())));
    }

    public function make(string $id): Exchange
    {
        $class = config("exchanges.drivers.{$id}");
        if ($class !== null) {
            return app($class);
        }

        if (in_array($id, $this->ccxtIds(), true)) {
            return app(CcxtExchange::class, ['ccxtId' => $id]);
        }

        throw new \InvalidArgumentException("Unknown exchange: {$id}");
    }

    public function active(): Exchange
    {
        return $this->make((string) config('exchanges.active', 'coinbase'));
    }

    /** @return array<int, string> */
    private function ccxtIds(): array
    {
        $raw = (string) config('exchanges.ccxt.enabled', '');

        return array_values(array_filter(array_map(trim(...), explode(',', $raw))));
    }
}
