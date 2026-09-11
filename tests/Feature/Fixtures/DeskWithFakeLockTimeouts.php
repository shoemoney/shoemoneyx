<?php

declare(strict_types=1);

namespace Tests\Feature\Fixtures;

use App\Desk\BankService;
use App\Desk\Chief;
use App\Desk\Desk;
use App\Desk\Execution\PostOnlyShadows;
use App\Desk\Reporter;
use App\Desk\Settings;
use App\Desk\StrategyRegistry;
use App\Exchange\Contracts\Exchange;
use App\Services\Market\ProductStatsBuilder;
use Illuminate\Contracts\Cache\Lock as CacheLockContract;
use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * Test-only Desk subclass that substitutes a lock which fails on command, on chosen calls, instead
 * of the real Cache::lock() — lets a test prove Desk::riskSweep()/cycle() survive a LockTimeoutException
 * from one position/candidate's mutate lock without needing real concurrency or a wall-clock wait for
 * a genuine block(5) timeout. $timeoutOnAttempt is 1-indexed, counting every mutateLock() call this
 * instance makes (enter/close/trim all funnel through it) across the whole test.
 *
 * Resolve via app(DeskWithFakeLockTimeouts::class, ['timeoutOnAttempt' => [2]]) — Laravel's container
 * autowires the real Desk dependencies and lets this one extra parameter be overridden per test.
 */
class DeskWithFakeLockTimeouts extends Desk
{
    public int $lockAttempts = 0;

    /** @param array<int, int> $timeoutOnAttempt */
    public function __construct(
        StrategyRegistry $strategies,
        Settings $settings,
        ProductStatsBuilder $stats,
        BankService $bankService,
        Reporter $reporter,
        Chief $chief,
        PostOnlyShadows $postOnlyShadows,
        Exchange $exchange,
        private array $timeoutOnAttempt = [],
    ) {
        parent::__construct($strategies, $settings, $stats, $bankService, $reporter, $chief, $postOnlyShadows, $exchange);
    }

    protected function mutateLock(string $mode, string $pid): CacheLockContract
    {
        $this->lockAttempts++;
        if (in_array($this->lockAttempts, $this->timeoutOnAttempt, true)) {
            return new class implements CacheLockContract
            {
                public function get($callback = null)
                {
                    throw new LockTimeoutException;
                }

                public function block($seconds, $callback = null)
                {
                    throw new LockTimeoutException;
                }

                public function release()
                {
                    return true;
                }

                public function owner()
                {
                    return 'fake-timeout-lock';
                }

                public function forceRelease() {}
            };
        }

        return parent::mutateLock($mode, $pid);
    }
}
