<?php

declare(strict_types=1);

namespace App\Desk;

use Illuminate\Contracts\Session\Session;

/**
 * Browser login for the master password. The session holds a keyed fingerprint of the credential
 * it logged in with (the stored hash, or the bootstrap key before the owner picks a password),
 * never the password itself, so changing the password ends every other browser's session on its
 * next request. With no credential at all the fingerprint is empty and nothing authenticates.
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
        $fingerprint = self::fingerprint();

        return $fingerprint !== '' && hash_equals($fingerprint, (string) $session->get('desk_authed', ''));
    }

    public static function fingerprint(): string
    {
        $source = app(Settings::class)->credentialFingerprintSource();

        return $source === '' ? '' : hash_hmac('sha256', $source, (string) config('app.key'));
    }
}
