<?php

declare(strict_types=1);

namespace App\Hub;

/**
 * HubClient refused or failed a call. `code` is the hub's error envelope code
 * (see docs/HUB_API.md), `retryAfter` is only set for rate_limited errors.
 *
 * `code` can't be declared `readonly string` here: \Exception (an ancestor of
 * \RuntimeException) already declares an untyped, non-readonly `$code` property,
 * and PHP forbids redeclaring it as readonly or with a type. It's set once, in
 * the constructor, and never written to again.
 */
final class HubException extends \RuntimeException
{
    /** @var string */
    public $code;

    public function __construct(string $code, string $message, public readonly ?int $retryAfter = null)
    {
        $this->code = $code;

        parent::__construct($message);
    }
}
