<?php

declare(strict_types=1);

namespace App\Desk\Execution;

/**
 * The product mutate lock's TTL is deliberately short (a crashed holder must not freeze stop-loss exits
 * for minutes), so a live order's create + status polling is bounded to a share of it: work stops, the
 * order is reported 'unknown', and reconcile settles it later under the lock. The HTTP client clamps each
 * request's timeout to what is left, so one slow call cannot overrun the budget either.
 */
final class OrderBudget
{
    /** Share of the lock TTL an order may spend before it must return. */
    private const SHARE = 0.7;

    private static ?int $deadline = null;

    public static function lockSeconds(): int
    {
        return max(90, (int) config('coinbase.timeout', 30) * 3);
    }

    /** @template T @param  \Closure(): T  $work @return T */
    public static function within(\Closure $work): mixed
    {
        $previous = self::$deadline;
        self::$deadline = now()->getTimestamp() + (int) floor(self::lockSeconds() * self::SHARE);
        try {
            return $work();
        } finally {
            self::$deadline = $previous;
        }
    }

    /** Seconds left in the current order's budget, or null outside one. */
    public static function remaining(): ?int
    {
        return self::$deadline === null ? null : self::$deadline - now()->getTimestamp();
    }

    public static function exhausted(): bool
    {
        $left = self::remaining();

        return $left !== null && $left <= 0;
    }

    /** A per-request timeout that, over $attempts tries, still fits in what is left. */
    public static function clamp(int $seconds, int $attempts = 1): int
    {
        $left = self::remaining();

        return $left === null ? $seconds : max(1, min($seconds, intdiv(max($left, 1), max(1, $attempts))));
    }
}
