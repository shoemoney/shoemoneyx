<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Desk\Arena\ArenaRunner;
use App\Desk\Chief;
use App\Desk\Desk;
use App\Desk\Exceptions\CycleInProgressException;
use App\Desk\Execution\PostOnlyShadows;
use App\Desk\Heartbeat;
use App\Desk\Reporter;
use App\Desk\Settings;
use App\Models\Position;
use App\Models\Product;
use App\Services\Market\CandleStore;
use App\Services\Market\ProductSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The long-running desk process: product sync hourly, candles every minute,
 * RISK every risk.poll_seconds, SCAN cycle every scan.poll_seconds.
 * Run under supervisord / pm2 / a zsh loop. Stop cleanly with desk:stop.
 */
class DeskRun extends Command
{
    protected $signature = 'desk:run {--once : one candle sync + risk + cycle, then exit}';

    protected $description = 'Run the desk continuously (CHIEF loop)';

    public function handle(Desk $desk, Chief $chief, Settings $settings, CandleStore $candles, ProductSync $products, Reporter $reporter, PostOnlyShadows $postOnlyShadows, ArenaRunner $arena): int
    {
        $desk->runsAs('run');
        $this->trapStopSignals($desk);
        try {
            $desk->reclaimStaleCycleLocks((bool) config('desk.reclaim_foreign_cycle_locks', false));
        } catch (\Throwable $e) {
            // A busy metadata guard or transient cache failure must not prevent risk management.
            // A blocked cycle retries reclamation below on the next normal scan interval.
            $reporter->warn('CHIEF', 'boot cycle-lock cleanup deferred: '.$e->getMessage());
        }
        $chief->setRunning(true);
        $reporter->info('CHIEF', 'desk:run started', ['mode' => $settings->mode(), 'strategy' => $settings->strategyKey(), 'pid' => getmypid()]);

        $lastProducts = 0;
        $lastCandles = 0;
        $lastRisk = 0;
        $lastCycle = 0;
        $lastArena = 0;
        $lastPostOnly = 0;
        $errors = [];

        while (! $desk->stopRequested()) {
            Heartbeat::beat('desk');
            $now = time();
            try {
                if ($now - $lastProducts >= 3600) {
                    $products->sync();
                    $lastProducts = $now;
                }
                if ($now - $lastCandles >= 60) {
                    $pids = Product::tracked()->pluck('product_id')->merge(Position::open()->pluck('product_id'))->unique();
                    $syncStarted = microtime(true);
                    foreach ($pids as $pid) {
                        Heartbeat::beat('desk');
                        try {
                            $candles->sync($pid, '1H', $now - 60 * 3600);
                            if ($settings->get('market.sync_1m', true)) {
                                $candles->sync($pid, '1m', $now - 6 * 3600);
                            }
                        } catch (\Throwable $e) {
                            $reporter->warn('CHIEF', "candles {$pid}: ".$e->getMessage());
                        }
                    }
                    $syncElapsed = microtime(true) - $syncStarted;
                    if ($syncElapsed > 30.0) {
                        $reporter->warn('CHIEF', sprintf('candle sync took %.1fs for %d products', $syncElapsed, $pids->count()));
                    }
                    $lastCandles = $now;
                }
                if ($now - $lastRisk >= (int) $settings->get('risk.poll_seconds', 60)) {
                    $riskStarted = microtime(true);
                    $desk->riskSweep();
                    $riskElapsed = microtime(true) - $riskStarted;
                    if ($riskElapsed > 30.0) {
                        $reporter->warn('CHIEF', sprintf('riskSweep took %.1fs', $riskElapsed));
                    }
                    $lastRisk = time();
                }
                if (! $desk->stopRequested() && $now - $lastCycle >= (int) $settings->get('scan.poll_seconds', 300) && ! $chief->halted()) {
                    try {
                        $run = $desk->cycle();
                        $this->line(sprintf('[%s] cycle #%d %s scanned=%d cand=%d pass=%d rej=%d fill=%d', now()->format('H:i:s'), $run->id, $run->status, $run->products_scanned, $run->candidates, $run->passed, $run->rejected, $run->filled));
                    } catch (CycleInProgressException $e) {
                        $this->line(sprintf('[%s] cycle skipped: another %s cycle is already running', now()->format('H:i:s'), $e->mode));
                        Log::info('desk:run cycle skipped, another cycle is running', ['mode' => $e->mode]);
                        // A predecessor's lock may only now have gone quiet long enough to reclaim.
                        $desk->reclaimStaleCycleLocks((bool) config('desk.reclaim_foreign_cycle_locks', false));
                    }
                    $lastCycle = time();
                }
                if (! $desk->stopRequested() && $now - $lastArena >= (int) $settings->get('scan.poll_seconds', 300) && ! $chief->halted()) {
                    $runs = $arena->cycle($desk);
                    if ($runs !== []) {
                        $this->line(sprintf('[%s] arena cycle: %d seat(s)', now()->format('H:i:s'), count($runs)));
                    }
                    $lastArena = time();
                }
                if (! $desk->stopRequested() && $now - $lastPostOnly >= 15 && $settings->get('post_only.shadow', false)) {
                    $postOnlyShadows->resolve();
                    $lastPostOnly = $now;
                }
                $errors = array_filter($errors, fn ($t) => $t > $now - 3600);
            } catch (\Throwable $e) {
                $errors[] = $now;
                $reporter->error('CHIEF', 'loop error: '.$e->getMessage());
                if (count($errors) >= (int) $settings->get('chief.deaths_per_hour_halt', 2) * 3) {
                    $chief->halt('repeated loop errors: '.$e->getMessage());
                }
            }

            if ($this->option('once') || $desk->stopRequested()) {
                break;
            }
            if (! $chief->running()) {
                $reporter->info('CHIEF', 'desk:run stopping (desk:stop received)');
                break;
            }
            // Poll cooperative stops as well as signals; a signal received between phases must
            // never incur another full sleep, even if it arrived just before this wait.
            for ($tick = 0; $tick < 50 && ! $desk->stopRequested() && $chief->running(); $tick++) {
                usleep(100_000);
            }
        }
        if ($desk->stopRequested()) {
            $reporter->info('CHIEF', 'desk:run stopped on a termination signal');
        }
        $this->restoreStopSignals();

        return self::SUCCESS;
    }

    /**
     * SIGTERM/SIGINT (docker stop, supervisor, Ctrl-C) ask the loop to wind down: the in-flight cycle starts no
     * new entries and unwinds through its own finally, which releases the cycle lock. Async signals interrupt
     * sleep() so the loop notices at once. No-op where pcntl is missing.
     */
    private function trapStopSignals(Desk $desk): bool
    {
        if (! function_exists('pcntl_async_signals')) {
            return false;
        }
        pcntl_async_signals(true);
        $stop = static fn () => $desk->requestStop();
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);

        return true;
    }

    private function restoreStopSignals(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
        }
    }
}
