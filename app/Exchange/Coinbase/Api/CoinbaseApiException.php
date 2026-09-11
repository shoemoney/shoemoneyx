<?php

declare(strict_types=1);

namespace App\Exchange\Coinbase\Api;

class CoinbaseApiException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly array $body = [])
    {
        parent::__construct($message, $status);
    }
}
