<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Hub\ContestEntry;
use App\Hub\ContestReporter;
use Illuminate\Console\Command;

class ReportContests extends Command
{
    protected $signature = 'hub:report-contests';

    protected $description = 'Push unsent fills and a throttled equity snapshot to the hub for every live contest entry';

    public function handle(ContestReporter $reporter): int
    {
        $entries = ContestEntry::where('state', 'live')->get();
        if ($entries->isEmpty()) {
            $this->line('no live contest entries');

            return self::SUCCESS;
        }

        foreach ($entries as $entry) {
            $reporter->report($entry);
        }

        $this->line("reported {$entries->count()} live contest entries");

        return self::SUCCESS;
    }
}
