<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Backtest;
use Illuminate\Support\Facades\DB;

/**
 * Marks a round's abandoned backtests as errored without deadlocking the worker pool.
 *
 * The obvious single statement -- UPDATE backtests SET status = 'error' WHERE id IN (...) AND
 * status = 'running' AND updated_at < ? -- makes MariaDB answer from
 * `backtests_status_updated_at_index`, so it locks secondary-index records BEFORE the primary-key
 * ones. A worker finishing a simulation locks the other way round: its UPDATE ... WHERE id = ? takes
 * the PRIMARY record first, then the secondary index the new status and updated_at belong to. Two
 * writers taking the same pair of locks in opposite orders is a textbook deadlock, and DeskOptimize
 * re-ran that statement every 5s against a table ~180 workers were writing to. InnoDB picked the
 * worker as the victim, so each collision rolled back a *completed* simulation -- and RunBacktest
 * runs on $tries = 1, so the job was not retried and an hour of work was discarded.
 *
 * Reading the stale ids first is a consistent non-locking read, so the overwhelmingly common case
 * (nothing is stale) takes no write locks at all. The rare real reap then updates row by row by
 * primary key -- the same lock order the workers use, which cannot invert against them.
 */
class StaleBacktestReaper
{
    public const MESSAGE = 'stale: worker died mid-run';

    /**
     * Ids of the round's rows whose worker has gone silent past the job timeout.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    protected function scanned(array $ids, int $staleAfterSeconds): array
    {
        return Backtest::whereIn('id', $ids)
            ->where('status', 'running')
            ->where('updated_at', '<', now()->subSeconds($staleAfterSeconds))
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * @param  array<int, int>  $ids  the round's backtests
     * @param  int  $staleAfterSeconds  silence that means the worker died rather than ran long
     * @return int  rows actually reaped
     */
    public function reap(array $ids, int $staleAfterSeconds): int
    {
        $reaped = 0;

        foreach ($this->scanned($ids, $staleAfterSeconds) as $id) {
            // Lock the primary record first, then recheck both status and heartbeat. A worker
            // may have completed or refreshed its lease since the non-locking scan.
            $reaped += DB::transaction(function () use ($id, $staleAfterSeconds) {
                $backtest = Backtest::whereKey($id)->lockForUpdate()->first();
                if ($backtest === null || $backtest->status !== 'running'
                    || $backtest->updated_at === null
                    || $backtest->updated_at->greaterThanOrEqualTo(now()->subSeconds($staleAfterSeconds))) {
                    return 0;
                }

                return Backtest::whereKey($id)->update(['status' => 'error', 'error' => self::MESSAGE]);
            }, 3);
        }

        return $reaped;
    }
}
