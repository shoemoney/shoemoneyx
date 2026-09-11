<?php

declare(strict_types=1);

namespace App\Exchange;

/** What an exchange adapter supports, so callers can branch on capability instead of class identity. */
readonly final class Capabilities
{
    public function __construct(
        public bool $spot,
        public bool $perps,
        public bool $websocket,
        public bool $historicalTrades,
        public bool $shorts,
    ) {}
}
