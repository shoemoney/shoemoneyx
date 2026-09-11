<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DeadlockRetry;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class DeadlockRetryTest extends TestCase
{
    private function deadlock(): PDOException
    {
        return new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
    }

    /** Records the backoff it would have slept instead of actually sleeping. */
    private function retry(int $attempts = DeadlockRetry::ATTEMPTS): DeadlockRetry
    {
        return new class($attempts) extends DeadlockRetry
        {
            /** @var array<int, int> */
            public array $slept = [];

            protected function pause(int $attempt): void
            {
                $this->slept[] = $attempt;
            }
        };
    }

    public function test_a_write_that_succeeds_first_time_is_not_retried(): void
    {
        $calls = 0;
        $retry = $this->retry();

        $retry->run(function () use (&$calls) {
            $calls++;
        });

        $this->assertSame(1, $calls);
        $this->assertSame([], $retry->slept);
    }

    public function test_a_transient_deadlock_is_retried_until_the_write_lands(): void
    {
        $calls = 0;
        $retry = $this->retry();

        $retry->run(function () use (&$calls) {
            $calls++;
            if ($calls < 3) {
                throw $this->deadlock();
            }
        });

        $this->assertSame(3, $calls, 'the write should be re-run until it succeeds');
        $this->assertSame([1, 2], $retry->slept, 'each failed attempt should back off before the next');
    }

    public function test_a_deadlock_that_never_clears_rethrows_after_the_attempt_budget(): void
    {
        $calls = 0;
        $retry = $this->retry(4);

        try {
            $retry->run(function () use (&$calls) {
                $calls++;
                throw $this->deadlock();
            });
            $this->fail('an unrecoverable deadlock should surface to the caller');
        } catch (PDOException $e) {
            $this->assertStringContainsString('1213 Deadlock', $e->getMessage());
        }

        $this->assertSame(4, $calls, 'the write should be attempted exactly the budgeted number of times');
        $this->assertSame([1, 2, 3], $retry->slept);
    }

    public function test_a_non_deadlock_failure_is_not_retried(): void
    {
        $calls = 0;
        $retry = $this->retry();

        try {
            $retry->run(function () use (&$calls) {
                $calls++;
                throw new RuntimeException('column value is garbage');
            });
            $this->fail('a real bug should surface immediately, not be retried');
        } catch (RuntimeException $e) {
            $this->assertSame('column value is garbage', $e->getMessage());
        }

        $this->assertSame(1, $calls, 'only concurrency errors are worth retrying');
        $this->assertSame([], $retry->slept);
    }

    public function test_backoff_grows_and_is_jittered_within_the_attempt_window(): void
    {
        $retry = new DeadlockRetry;

        $first = DeadlockRetry::backoffFor(1);
        $fourth = DeadlockRetry::backoffFor(4);

        $this->assertGreaterThanOrEqual(DeadlockRetry::BACKOFF_US, $first);
        $this->assertLessThanOrEqual(2 * DeadlockRetry::BACKOFF_US, $first);
        $this->assertGreaterThanOrEqual(8 * DeadlockRetry::BACKOFF_US, $fourth);
        $this->assertLessThanOrEqual(16 * DeadlockRetry::BACKOFF_US, $fourth);
        $this->assertInstanceOf(DeadlockRetry::class, $retry);
    }
}
