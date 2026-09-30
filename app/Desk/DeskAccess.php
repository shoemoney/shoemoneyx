<?php

declare(strict_types=1);

namespace App\Desk;

use Illuminate\Contracts\Session\Session;

/**
 * Single answer to "is this browser allowed to see the desk?" for the websocket channel auth.
 */
final class DeskAccess
{
    public static function allows(Session $session): bool
    {
        $settings = app(Settings::class);

        return $settings->hasMasterPassword() && ! $settings->needsPasswordSetup() && DeskLogin::check($session);
    }
}
