<?php

declare(strict_types=1);

namespace App\Ai\Exceptions;

/** No API key is resolvable at all: no connected account and no env fallback. */
final class AiNoConnectionException extends \RuntimeException {}
