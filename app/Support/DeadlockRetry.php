<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Retries a write that races the whole backtest worker pool.
 *
 * DB::transaction($write, $attempts) re-runs a deadlocked transaction *immediately* --
 * ManagesTransactions loops straight back with no pause. With ~130 workers spread across
 * .3/.4/.5 all updating `backtests` on one mariadb, the losers of a lock race re-collide
 * inside the same lock window and burn every attempt in microseconds, so the completion
 * write fails and an hour of finished simulation is discarded. Sleeping a jittered,
 * doubling interval between attempts spreads the losers out so the retry can actually win.
 */
class DeadlockRetry
{
    use DetectsConcurrencyErrors;

    public const ATTEMPTS = 6;

    /** Base backoff, doubled per attempt and jittered up to 2x to avoid a synchronised retry stampede. */
    public const BACKOFF_US = 20_000;

    public function __construct(private int $attempts = self::ATTEMPTS) {}

    /** Microseconds to wait after a given failed attempt. */
    public static function backoffFor(int $attempt): int
    {
        return random_int(self::BACKOFF_US, 2 * self::BACKOFF_US) << ($attempt - 1);
    }

    /**
     * @throws Throwable the original failure once the attempt budget is spent, or immediately if it isn't a deadlock
     */
    public function run(Closure $write): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                DB::transaction($write, 1);

                return;
            } catch (Throwable $e) {
                if ($attempt >= $this->attempts || ! $this->causedByConcurrencyError($e)) {
                    throw $e;
                }

                $this->pause($attempt);
            }
        }
    }

    protected function pause(int $attempt): void
    {
        usleep(self::backoffFor($attempt));
    }
}
