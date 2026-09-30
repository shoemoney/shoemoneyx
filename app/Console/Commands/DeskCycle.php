<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Desk\Desk;
use App\Desk\Exceptions\CycleInProgressException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DeskCycle extends Command
{
    protected $signature = 'desk:cycle';

    protected $description = 'Run one SCAN -> VET -> SIZE -> FILLS cycle';

    public function handle(Desk $desk): int
    {
        try {
            $run = $desk->cycle();
        } catch (CycleInProgressException $e) {
            $this->line("cycle skipped: another {$e->mode} cycle is already running");
            Log::info('desk:cycle skipped, another cycle is running', ['mode' => $e->mode]);

            return self::SUCCESS;
        }
        $this->table(['run', 'mode', 'strategy', 'status', 'scanned', 'candidates', 'passed', 'rejected', 'filled', 'error'], [[
            $run->id, $run->mode, $run->strategy, $run->status, $run->products_scanned, $run->candidates, $run->passed, $run->rejected, $run->filled, $run->error,
        ]]);
        foreach ($run->candidates()->get() as $c) {
            $this->line(sprintf('#%d %-10s score %6.3f  %-12s %s', $c->rank, $c->product_id, $c->score, $c->verdict ?? '-', $c->verdict === 'REJECT' ? "[{$c->failed_check}] {$c->why}" : ($c->size_usd ? '$'.$c->size_usd.' '.$c->size_why : ($c->size_why ?? $c->rank_reason))));
        }

        return $run->status === 'error' ? self::FAILURE : self::SUCCESS;
    }
}
