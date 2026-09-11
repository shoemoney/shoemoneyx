<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AgentBacktestLoop as AgentBacktestLoopJob;
use App\Models\StrategyPlugin;
use Illuminate\Console\Command;

class AgentBacktestLoop extends Command
{
    protected $signature = 'agent:backtest-loop {--once : Do a single pass — the only mode there is, and what the scheduler calls}';

    protected $description = 'Backtest every auto-backtest strategy whose last run has aged out, and file the agent review.';

    /**
     * Dispatched synchronously on purpose: the scheduler calls this every fifteen
     * minutes and the work has to happen whether or not a queue worker is running.
     */
    public function handle(): int
    {
        $plugins = StrategyPlugin::where('auto_backtest', true)->get();

        foreach ($plugins as $plugin) {
            try {
                AgentBacktestLoopJob::dispatchSync($plugin->id);
            } catch (\Throwable $e) {
                $this->error($plugin->key.': '.$e->getMessage());
            }
        }

        $this->info($plugins->count().' auto-backtest strategies visited.');

        return self::SUCCESS;
    }
}
