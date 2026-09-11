<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A coin's long or short set was replaced. Carries the diff so the toast can say what changed. */
class ChampionPromoted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<string, mixed>  $before  previous set, search-space keys
     * @param  array<string, mixed>  $after  promoted set, search-space keys
     */
    public function __construct(
        public string $coin,
        public string $side,
        public array $before,
        public array $after,
        public ?float $train,
        public ?float $test,
        public ?float $championTrain,
        public ?float $championTest,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('optimizer')];
    }

    public function broadcastAs(): string
    {
        return 'champion.promoted';
    }

    /** Only the keys that changed: [key => [from, to]]. */
    public function diff(): array
    {
        $d = [];
        foreach ($this->after as $k => $v) {
            $from = $this->before[$k] ?? null;
            if ($from != $v) {
                $d[$k] = [$from, $v];
            }
        }

        return $d;
    }

    public function broadcastWith(): array
    {
        return [
            'coin' => $this->coin,
            'side' => $this->side,
            'train' => $this->train,
            'test' => $this->test,
            'champion' => ['train' => $this->championTrain, 'test' => $this->championTest],
            'diff' => $this->diff(),
            'params' => $this->after,
            'at' => now()->toIso8601String(),
        ];
    }
}
