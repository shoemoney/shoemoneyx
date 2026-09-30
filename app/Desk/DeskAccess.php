<?php

declare(strict_types=1);

namespace App\Desk;

use Illuminate\Contracts\Session\Session;

/**
 * Single answer to "is this browser allowed to see the desk?" for the websocket channel auth.
 * Swap the body for DeskLogin::check($session) once that class lands.
 */
final class DeskAccess
{
    public static function allows(Session $session): bool
    {
        return app(Settings::class)->masterPassword() === '' || $session->get('desk_authed', false) === true;
    }
}
