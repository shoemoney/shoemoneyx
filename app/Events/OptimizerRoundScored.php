<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\OptimizerRound;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A round closed: champion vs best candidate, and the verdict. */
class OptimizerRoundScored implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public OptimizerRound $round) {}

    public function broadcastOn(): array
    {
        return [new Channel('optimizer')];
    }

    public function broadcastAs(): string
    {
        return 'round.scored';
    }

    public function broadcastWith(): array
    {
        $r = $this->round;

        return [
            'id' => $r->id,
            'coin' => $r->product_id,
            'side' => $r->side,
            'candidates' => $r->candidates,
            'cash' => (float) $r->cash,
            'champion' => ['train' => $r->champion_train, 'test' => $r->champion_test, 'params' => $r->champion_params],
            'best' => ['train' => $r->best_train, 'test' => $r->best_test, 'params' => $r->best_params, 'calmar' => $r->best_calmar, 'dsr' => $r->best_dsr, 'plateau' => $r->best_plateau, 'test_min' => $r->best_test_min],
            'rank' => $r->rank,
            'folds' => (int) $r->folds,
            'tag' => $r->tag,
            'cache_hits' => (int) $r->cache_hits,
            'promoted' => (bool) $r->promoted,
            'note' => $r->note,
            'train_from' => $r->train_from?->toIso8601String(),
            'test_to' => $r->test_to?->toIso8601String(),
            'at' => now()->toIso8601String(),
        ];
    }
}
