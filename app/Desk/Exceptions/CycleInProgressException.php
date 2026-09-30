<?php

declare(strict_types=1);

namespace App\Desk\Exceptions;

class CycleInProgressException extends \RuntimeException
{
    public function __construct(public readonly string $mode)
    {
        parent::__construct('a cycle is already running');
    }
}
