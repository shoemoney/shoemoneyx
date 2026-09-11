<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Strategies\Sync\StrategySync;
use Illuminate\Console\Command;

class StrategiesSync extends Command
{
    protected $signature = 'strategies:sync {--import : Import every newly-found strategy}';

    protected $description = 'Check the community strategies repo for new/updated strategies, optionally importing the new ones.';

    public function handle(StrategySync $sync): int
    {
        try {
            $result = $sync->check();
        } catch (\RuntimeException $e) {
            $this->error('sync check failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d new, %d updated, %d up to date.',
            count($result['new']), count($result['updated']), $result['up_to_date'],
        ));
        foreach ($result['new'] as $entry) {
            $this->line("  new:     {$entry['id']} v{$entry['version']}");
        }
        foreach ($result['updated'] as $entry) {
            $this->line("  updated: {$entry['id']} v{$entry['version']}");
        }

        if ($this->option('import')) {
            foreach ($result['new'] as $entry) {
                $imported = $sync->import($entry['id']);
                $this->info($imported['imported']
                    ? "imported {$entry['id']} as v{$imported['plugin']->current_version}"
                    : "FAILED {$entry['id']}: ".($imported['error'] ?? 'unknown error'));
            }
        }

        return self::SUCCESS;
    }
}
