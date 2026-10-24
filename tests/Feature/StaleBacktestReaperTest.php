<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Backtest;
use App\Support\StaleBacktestReaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DeskOptimize::wait() reaped abandoned rows with one blind statement every 5s:
 * UPDATE backtests SET status = 'error' WHERE id IN (...) AND status = 'running' AND updated_at < ?
 * MariaDB answers that from `backtests_status_updated_at_index`, taking secondary-index locks before
 * primary-key ones -- the exact inverse of a worker's UPDATE ... WHERE id = ?. The two orders
 * deadlocked, InnoDB rolled back the worker, and RunBacktest ($tries = 1) discarded the finished
 * simulation.
 *
 * sqlite has no such lock manager, so these assert the two properties that remove the inversion on
 * MariaDB: an idle reap must issue no write at all, and a real reap must address rows one at a time
 * by primary key.
 */
class StaleBacktestReaperTest extends TestCase
{
    use RefreshDatabase;

    /** Matches DeskOptimize: the 3600s job timeout plus a margin. */
    private const STALE_AFTER = 3700;

    private function makeBacktest(array $extra = []): Backtest
    {
        return Backtest::create($extra + [
            'strategy' => 'a1', 'products' => ['BTC-USD'],
            'from' => now()->subDays(2), 'to' => now()->subDay(),
            'starting_cash' => 1000, 'status' => 'queued', 'params' => [],
        ]);
    }

    /** A row last touched long enough ago that its worker must be gone. */
    private function abandoned(): Backtest
    {
        $bt = $this->makeBacktest(['status' => 'running']);
        Backtest::whereKey($bt->id)->update(['updated_at' => now()->subSeconds(self::STALE_AFTER + 60)]);

        return $bt->fresh();
    }

    /** @return array<int, array{query: string, bindings: array}> */
    private function writesDuring(callable $work): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        DB::disableQueryLog();

        return array_values(array_filter(
            DB::getQueryLog(),
            fn ($q) => str_starts_with(strtolower(ltrim($q['query'])), 'update')
        ));
    }

    public function test_an_idle_reap_issues_no_write_at_all(): void
    {
        $fresh = $this->makeBacktest(['status' => 'running']);
        $done = $this->makeBacktest(['status' => 'done']);

        $writes = $this->writesDuring(fn () => (new StaleBacktestReaper)->reap([$fresh->id, $done->id], self::STALE_AFTER));

        $this->assertSame([], $writes, 'with nothing stale the reaper must take no write locks — this is what stops it racing the workers');
        $this->assertSame('running', $fresh->fresh()->status);
    }

    public function test_an_abandoned_row_is_marked_error(): void
    {
        $bt = $this->abandoned();

        $reaped = (new StaleBacktestReaper)->reap([$bt->id], self::STALE_AFTER);

        $this->assertSame(1, $reaped);
        $this->assertSame('error', $bt->fresh()->status);
        $this->assertSame(StaleBacktestReaper::MESSAGE, $bt->fresh()->error);
    }

    public function test_each_reaped_row_is_addressed_by_primary_key(): void
    {
        $a = $this->abandoned();
        $b = $this->abandoned();

        $writes = $this->writesDuring(fn () => (new StaleBacktestReaper)->reap([$a->id, $b->id], self::STALE_AFTER));

        $this->assertCount(2, $writes, 'each row is its own statement, so every write is a primary-key update');
        foreach ($writes as $w) {
            $this->assertStringContainsString('id = ?', str_replace(['"', '`'], '', $w['query']), 'the reap must locate the row by primary key, not by the status/updated_at index');
            $this->assertStringNotContainsString('updated_at <', str_replace(['"', '`'], '', $w['query']), 'ranging on updated_at is what drove MariaDB to the secondary index first');
        }
    }

    public function test_a_row_that_finishes_after_the_scan_is_not_clobbered(): void
    {
        $bt = $this->abandoned();
        $reaper = new class extends StaleBacktestReaper
        {
            public ?\Closure $afterScan = null;

            protected function scanned(array $ids, int $staleAfterSeconds): array
            {
                $found = parent::scanned($ids, $staleAfterSeconds);
                ($this->afterScan)();

                return $found;
            }
        };
        // The worker lands its completion write in the window between the scan and the reap.
        $reaper->afterScan = fn () => Backtest::whereKey($bt->id)->update(['status' => 'done']);

        $reaped = $reaper->reap([$bt->id], self::STALE_AFTER);

        $this->assertSame(0, $reaped, 'a simulation that finished must not be overwritten with an error');
        $this->assertSame('done', $bt->fresh()->status);
    }

    public function test_a_row_still_being_worked_is_left_alone(): void
    {
        $bt = $this->makeBacktest(['status' => 'running']);

        $reaped = (new StaleBacktestReaper)->reap([$bt->id], self::STALE_AFTER);

        $this->assertSame(0, $reaped);
        $this->assertSame('running', $bt->fresh()->status);
    }

    public function test_a_worker_heartbeat_after_the_scan_prevents_reaping(): void
    {
        $bt = $this->abandoned();
        $reaper = new class extends StaleBacktestReaper
        {
            protected function scanned(array $ids, int $staleAfterSeconds): array
            {
                $found = parent::scanned($ids, $staleAfterSeconds);
                Backtest::whereIn('id', $found)->update(['updated_at' => now()]);

                return $found;
            }
        };

        $this->assertSame(0, $reaper->reap([$bt->id], self::STALE_AFTER));
        $this->assertSame('running', $bt->fresh()->status);
    }
}
