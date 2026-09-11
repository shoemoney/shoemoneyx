<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Desk\Backtester;
use App\Models\Backtest;
use App\Services\Market\CandleStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunBacktest implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $backtestId) {}

    /** Killed by timeout / memory / max attempts: never leave the row stuck in "running". */
    public function failed(?\Throwable $e = null): void
    {
        // A builder update skips the model's mutators, so cap the message here too: an
        // unbounded one overflows TEXT and this last-resort write is the one that must not fail.
        $message = mb_substr('job failed: '.($e?->getMessage() ?? 'unknown'), 0, Backtest::ERROR_MAX);

        Backtest::where('id', $this->backtestId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'error', 'error' => $message]);
    }

    public function handle(Backtester $bt, CandleStore $store): void
    {
        $row = Backtest::findOrFail($this->backtestId);
        foreach ($row->products as $pid) {
            if ($store->fresh($pid, '1H', 300)) {
                continue;   // desk loop already synced this product's 1H candles this minute
            }
            try {
                $store->sync($pid, '1H', $row->from->getTimestamp() - 50 * 3600);
            } catch (\Throwable) {
                // missing history for one product is not fatal
            }
        }
        $bt->runInto($row);
    }
}
