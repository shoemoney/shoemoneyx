<?php

declare(strict_types=1);

namespace App\Exchange;

/** One error type for every venue call a generic adapter makes, so callers catch one thing. */
class ExchangeException extends \RuntimeException {}
