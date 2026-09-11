<?php

declare(strict_types=1);

namespace App\Ai\Exceptions;

/** App\Ai\Gate refused the call. `reason` is what the caller maps to an HTTP status. */
final class AiGateException extends \RuntimeException
{
    public const RATE_LIMITED = 'rate_limited';

    public const SUSPENDED = 'suspended';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
