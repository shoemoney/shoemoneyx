<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Desk\Arena\ArenaRunner;
use App\Desk\Chief;
use App\Desk\Desk;
use App\Desk\Execution\PostOnlyShadows;
use App\Desk\Reporter;
use App\Desk\Settings;
use App\Models\Position;
use App\Models\Product;
use App\Services\Market\CandleStore;
use App\Services\Market\ProductSync;
use Illuminate\Console\Command;

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
        $chief->setRunning(true);
        $reporter->info('CHIEF', 'desk:run started', ['mode' => $settings->mode(), 'strategy' => $settings->strategyKey(), 'pid' => getmypid()]);

        $lastProducts = 0;
        $lastCandles = 0;
        $lastRisk = 0;
        $lastCycle = 0;
        $lastArena = 0;
        $lastPostOnly = 0;
        $errors = [];

        while (true) {
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
                if ($now - $lastCycle >= (int) $settings->get('scan.poll_seconds', 300) && ! $chief->halted()) {
                    $run = $desk->cycle();
                    $this->line(sprintf('[%s] cycle #%d %s scanned=%d cand=%d pass=%d rej=%d fill=%d', now()->format('H:i:s'), $run->id, $run->status, $run->products_scanned, $run->candidates, $run->passed, $run->rejected, $run->filled));
                    $lastCycle = time();
                }
                if ($now - $lastArena >= (int) $settings->get('scan.poll_seconds', 300) && ! $chief->halted()) {
                    $runs = $arena->cycle();
                    if ($runs !== []) {
                        $this->line(sprintf('[%s] arena cycle: %d seat(s)', now()->format('H:i:s'), count($runs)));
                    }
                    $lastArena = time();
                }
                if ($now - $lastPostOnly >= 15 && $settings->get('post_only.shadow', false)) {
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

            if ($this->option('once')) {
                break;
            }
            if (! $chief->running()) {
                $reporter->info('CHIEF', 'desk:run stopping (desk:stop received)');
                break;
            }
            sleep(5);
        }

        return self::SUCCESS;
    }
}
