<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Desk\EndOfDayReport;
use Carbon\Carbon;
use Illuminate\Console\Command;

class DeskReport extends Command
{
    protected $signature = 'desk:report {--date=} {--send : also push to Telegram}';

    protected $description = 'End-of-day report (assembled, never invented)';

    public function handle(EndOfDayReport $report): int
    {
        $day = $this->option('date') ? Carbon::parse((string) $this->option('date')) : now();
        $r = $this->option('send') ? $report->send($day) : $report->build($day);
        $this->line(strip_tags($report->text($r)));

        return self::SUCCESS;
    }
}
