<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * After a worker restart, jobs that were mid-flight stay in the queue's reserved set until retry_after
 * (4000 s, above the 3600 s job timeout) expires, and an optimizer round waits on them for up to its
 * deadline. This puts every reservation taken before --since back on the queue with attempts reset so
 * the new workers simply run them again.
 *
 *   php artisan desk:release-orphans                 # everything reserved before now
 *   php artisan desk:release-orphans --since="2026-09-05 14:44:30" --dry
 */
class DeskReleaseOrphans extends Command
{
    protected $signature = 'desk:release-orphans {--since= : UTC time the workers were restarted (default: now)} {--queues=backtests,backtests-light} {--dry : report only}';

    protected $description = 'Re-queue reserved jobs whose worker died in a restart';

    public function handle(): int
    {
        $cutoff = $this->option('since') ? strtotime($this->option('since').' UTC') : time();
        $retry = (int) config('queue.connections.redis.retry_after', 90);
        $redis = Redis::connection((string) config('queue.connections.redis.connection', 'default'));
        $total = 0;
        foreach (explode(',', (string) $this->option('queues')) as $name) {
            $q = 'queues:'.trim($name);
            $reserved = $redis->zrange("{$q}:reserved", 0, -1, ['withscores' => true]);
            $orphans = 0;
            foreach ($reserved as $payload => $score) {
                if ((int) $score - $retry >= $cutoff) {
                    continue;
                }
                $orphans++;
                if (! $this->option('dry')) {
                    $job = json_decode($payload, true);
                    $job['attempts'] = 0;
                    $redis->zrem("{$q}:reserved", $payload);
                    $redis->rpush($q, json_encode($job));
                }
            }
            $total += $orphans;
            $this->line(sprintf('%s: %d reserved, %d orphaned%s', $q, count($reserved), $orphans, $this->option('dry') ? ' (dry run)' : ' → released'));
        }

        return self::SUCCESS;
    }
}
