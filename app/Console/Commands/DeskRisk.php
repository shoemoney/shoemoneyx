<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Desk\Desk;
use Illuminate\Console\Command;

class DeskRisk extends Command
{
    protected $signature = 'desk:risk';

    protected $description = 'RISK sweep over every open position (own timer, final authority)';

    public function handle(Desk $desk): int
    {
        $rows = $desk->riskSweep();
        $rows === [] ? $this->line('no open positions') : $this->table(['position', 'action', 'rule'], $rows);

        return self::SUCCESS;
    }
}
