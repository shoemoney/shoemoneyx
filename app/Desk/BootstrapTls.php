<?php

declare(strict_types=1);

namespace App\Desk;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

/** The AMI must activate a trusted certificate before accepting its first-login credential. */
final class BootstrapTls
{
    public static function blocked(Request $request): bool
    {
        if (config('desk.first_login_guide') !== 'aws' || ! app(Settings::class)->needsPasswordSetup()) {
            return false;
        }

        // nginx supplies HTTPS from its own TLS connection. Untrusted forwarded headers do not
        // make an HTTP request secure, and a marker left over from a different IP is not enough.
        if (! $request->isSecure()) {
            return true;
        }

        $path = (string) config('desk.tls_status_path');
        $status = $path !== '' && is_readable($path) ? @file_get_contents($path) : false;
        if (! is_string($status) || preg_match('/\Atrusted (ip|dns):([^\s]+) (\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z)\n?\z/', $status, $parts) !== 1) {
            return true;
        }

        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $parts[3]);
        if ($time === false || $time->format('Y-m-d\TH:i:s\Z') !== $parts[3]) {
            return true;
        }

        try {
            $host = strtolower(trim($request->getHost(), '[]'));
        } catch (SuspiciousOperationException) {
            return true;
        }

        if ($parts[1] === 'ip') {
            return filter_var($parts[2], FILTER_VALIDATE_IP) === false
                || @inet_pton($parts[2]) !== @inet_pton($host);
        }

        return filter_var($parts[2], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || strcasecmp($parts[2], $host) !== 0;
    }
}
