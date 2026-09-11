<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Backtest;
use App\Support\CandidateSummary;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** One backtest finished (done or error). The firehose: ~7/s at full farm tilt. */
class BacktestScored implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Backtest $backtest) {}

    public function broadcastOn(): array
    {
        return [new Channel('optimizer')];
    }

    public function broadcastAs(): string
    {
        return 'backtest.scored';
    }

    public function broadcastWith(): array
    {
        $b = $this->backtest;
        $p = (array) ($b->params ?? []);
        $opt = (array) ($p['_opt'] ?? []);
        $side = (string) ($opt['side'] ?? 'long');
        $s = (array) ($b->stats ?? []);

        return [
            'id' => $b->id,
            'coin' => $opt['coin'] ?? ($b->products[0] ?? null),
            'side' => $side,
            'window' => $opt['window'] ?? null,
            'cand' => $opt['cand'] ?? null,
            'batch' => $opt['batch'] ?? null,
            'sim' => $p['_sim']['tag'] ?? null,
            'status' => $b->status,
            'error' => $b->error ? substr((string) $b->error, 0, 120) : null,
            'params' => CandidateSummary::params($p, $side),
            'ret' => $s['total_return_pct'] ?? null,
            'trades' => $s['trades'] ?? null,
            'win' => $s['win_rate'] ?? null,
            'pf' => $s['profit_factor'] ?? null,
            'dd' => $s['max_drawdown_pct'] ?? null,
            'at' => now()->toIso8601String(),
        ];
    }
}
