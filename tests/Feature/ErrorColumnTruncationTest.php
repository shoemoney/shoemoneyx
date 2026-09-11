<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunBacktest;
use App\Models\Backtest;
use App\Models\DeskRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A PDOException message embeds the whole failing statement; a bulk candle insert produced a
 * 288 KB one. Written raw into the TEXT `error` column that overflows (SQLSTATE 22001), so the
 * write recording a failure failed itself and left the row in its pre-failure status.
 *
 * These assert the invariant rather than the cap's exact value: whatever is stored must fit in
 * TEXT and must still open with the part of the message a human reads first. Note sqlite (the
 * test driver) silently accepts oversized TEXT, so only an explicit length check catches this.
 */
class ErrorColumnTruncationTest extends TestCase
{
    use RefreshDatabase;

    /** MySQL/MariaDB TEXT, in bytes. */
    private const TEXT_LIMIT = 65535;

    /** Comfortably past TEXT, like the 288 KB message seen in production. */
    private function hugeMessage(): string
    {
        return 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock '.str_repeat('x', 300_000);
    }

    private function assertFitsInTextColumn(?string $stored, string $prefix): void
    {
        $this->assertNotNull($stored);
        $this->assertLessThan(self::TEXT_LIMIT, strlen($stored), 'stored error must fit in a TEXT column');
        $this->assertStringStartsWith($prefix, $stored, 'the readable head of the message must survive');
    }

    private function makeBacktest(array $extra = []): Backtest
    {
        return Backtest::create($extra + [
            'strategy' => 'mr', 'products' => ['BTC-USD'],
            'from' => now()->subDays(2), 'to' => now()->subDay(),
            'starting_cash' => 1000, 'status' => 'queued', 'params' => [],
        ]);
    }

    public function test_backtest_error_is_capped_on_write(): void
    {
        $bt = $this->makeBacktest();
        $bt->update(['status' => 'error', 'error' => $this->hugeMessage()]);

        $this->assertFitsInTextColumn($bt->fresh()->error, 'SQLSTATE[40001]');
    }

    public function test_desk_run_error_is_capped_on_write(): void
    {
        $run = DeskRun::create(['mode' => 'paper', 'strategy' => 'mr', 'degraded' => false, 'started_at' => now()]);
        $run->update(['status' => 'error', 'error' => $this->hugeMessage()]);

        $this->assertFitsInTextColumn($run->fresh()->error, 'SQLSTATE[40001]');
    }

    /** The failed() safety net uses a builder update, which skips model mutators entirely. */
    public function test_failed_job_safety_net_caps_the_message_and_frees_the_row(): void
    {
        $bt = $this->makeBacktest(['status' => 'running']);

        (new RunBacktest($bt->id))->failed(new \RuntimeException($this->hugeMessage()));

        $fresh = $bt->fresh();
        $this->assertSame('error', $fresh->status, 'row must not stay stuck in "running"');
        $this->assertFitsInTextColumn($fresh->error, 'job failed: ');
    }

    public function test_short_messages_and_null_are_left_alone(): void
    {
        $bt = $this->makeBacktest();

        $bt->update(['error' => 'boom']);
        $this->assertSame('boom', $bt->fresh()->error);

        $bt->update(['error' => null]);
        $this->assertNull($bt->fresh()->error);
    }
}
