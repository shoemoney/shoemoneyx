<?php

declare(strict_types=1);

namespace App\Desk;

use Illuminate\Contracts\Session\Session;

/**
 * Browser login for the master password. The session holds a keyed fingerprint of the password
 * it logged in with, never the password itself, so changing the password ends every other
 * browser's session on its next request.
 */
final class DeskLogin
{
    public static function grant(Session $session): void
    {
        $session->regenerate();
        $session->put('desk_authed', self::fingerprint());
    }

    public static function check(Session $session): bool
    {
        return hash_equals(self::fingerprint(), (string) $session->get('desk_authed', ''));
    }

    private static function fingerprint(): string
    {
        return hash_hmac('sha256', app(Settings::class)->masterPassword(), (string) config('app.key'));
    }
}
