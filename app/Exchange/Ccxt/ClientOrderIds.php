<?php

declare(strict_types=1);

namespace App\Exchange\Ccxt;

use Illuminate\Support\Str;

/**
 * The client order id a venue will accept. Every venue gets 32 lowercase hex unless its documented
 * rules (or the vendored ccxt code) say otherwise. The result is stored on the OrderIntent and reused
 * verbatim for resends and lookups, so what the venue echoes back is what lookups compare against.
 *
 * Checked against vendor/ccxt/ccxt/php/<id>.php and the venue docs:
 *  - gate: text must be 't-' prefixed with 28 chars max after it; ccxt counts the whole string against
 *    28 before it would add the prefix, so we send 't-' + 26 hex (ccxt leaves an id starting with 't' alone).
 *  - bitfinex: cid is an integer (ccxt passes it raw; its futures branch uses safe_integer); 44 bits
 *    stays inside Bitfinex's 45-bit cid. Bitfinex only needs it unique within a UTC day.
 *  - 32-hex is fine on: binance (36 max, ccxt's sample ids are 32), okx (clOrdId up to 32), bybit
 *    (orderLinkId max 36), kucoin (clientOid; ccxt's own default is a 36-char uuid), bitget (50 max),
 *    htx spot (64 max), gemini (under 100), bitstamp (180 max), kraken (docs list a 32-hex short UUID).
 */
final class ClientOrderIds
{
    public static function generate(string $ccxtId): string
    {
        return self::fromHex($ccxtId, str_replace('-', '', (string) Str::uuid()));
    }

    /**
     * What goes into the ccxt params. The stored id stays a string; Bitfinex wants cid as a JSON integer
     * (int45 per https://docs.bitfinex.com/reference/rest-auth-submit-order), and 44 bits fits it and a 64-bit PHP int.
     */
    public static function forRequest(string $ccxtId, string $stored): int|string
    {
        return $ccxtId === 'bitfinex' ? (int) $stored : $stored;
    }

    /** Pure and deterministic: the venue-formatted id for a given 32-hex seed. */
    public static function fromHex(string $ccxtId, string $hex32): string
    {
        return match ($ccxtId) {
            'gate' => 't-'.substr($hex32, 0, 26),
            'bitfinex' => (string) hexdec(substr($hex32, 0, 11)),
            default => $hex32,
        };
    }
}
