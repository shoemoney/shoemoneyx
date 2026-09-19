<?php

declare(strict_types=1);

namespace App\Desk\Strategies;

/** Thrown by {@see Formula::parse()} with the exact byte offset the grammar broke at. */
final class FormulaParseError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $offset)
    {
        parent::__construct($message);
    }
}
