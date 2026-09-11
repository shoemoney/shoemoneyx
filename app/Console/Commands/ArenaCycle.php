<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Desk\Arena\ArenaRunner;
use Illuminate\Console\Command;

class ArenaCycle extends Command
{
    protected $signature = 'arena:cycle';

    protected $description = 'Run one SCAN -> VET -> SIZE -> FILLS -> RISK cycle for every active arena seat';

    public function handle(ArenaRunner $arena): int
    {
        $runs = $arena->cycle();
        if ($runs === []) {
            $this->line('no active arena seats');

            return self::SUCCESS;
        }

        $this->table(['seat_id', 'run_id', 'status'], array_map(fn ($r) => [$r['seat_id'], $r['run_id'], $r['status']], $runs));

        return self::SUCCESS;
    }
}
