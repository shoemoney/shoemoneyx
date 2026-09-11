<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PostOnlyShadow;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Tallies the post-only shadow A/B: how often a resting maker limit would have filled instead of
 * the real taker fill, how much that would have saved, and how many trades it would have missed.
 */
class DeskPostOnlyReport extends Command
{
    protected $signature = 'desk:post-only-report {--since=} {--product=}';

    protected $description = 'Report post-only shadow fill rate and bps saved vs. the real paper fills';

    protected $columns = ['shadows', 'filled', 'chased', 'expired', 'resting', 'fill_rate_%', 'avg_saved_bps', 'total_saved_usd', 'avg_miss_move_pct'];

    public function handle(): int
    {
        $since = $this->option('since') ? Carbon::parse($this->option('since')) : now()->subDays(7);
        $query = PostOnlyShadow::where('placed_at', '>=', $since);
        if ($product = $this->option('product')) {
            $query->where('product_id', strtoupper($product));
        }
        $shadows = $query->get();

        $byKind = [];
        foreach (['entry', 'add', 'trim'] as $kind) {
            $byKind[] = array_merge(['kind' => $kind], $this->stats($shadows->where('kind', $kind)));
        }
        $byKind[] = array_merge(['kind' => 'total'], $this->stats($shadows));
        $this->table(array_merge(['kind'], $this->columns), $byKind);

        $byProduct = [];
        foreach ($shadows->pluck('product_id')->unique()->sort() as $pid) {
            $byProduct[] = array_merge(['product' => $pid], $this->stats($shadows->where('product_id', $pid)));
        }
        $this->table(array_merge(['product'], $this->columns), $byProduct);

        return self::SUCCESS;
    }

    /** @return array<string, int|float|string|null> */
    private function stats(Collection $group): array
    {
        $total = $group->count();
        $filled = $group->where('status', 'filled');
        $expired = $group->where('status', 'expired');

        $bps = $filled->map(function (PostOnlyShadow $s) {
            $notional = $s->qty * $s->actual_price;

            return $notional > 0 ? $s->saved_usd / $notional * 10_000 : null;
        })->filter(fn ($v) => $v !== null);

        return [
            'shadows' => $total,
            'filled' => $filled->count(),
            'chased' => $group->where('chase_count', '>=', 1)->count(),
            'expired' => $expired->count(),
            'resting' => $group->where('status', 'resting')->count(),
            'fill_rate_%' => $total > 0 ? round($filled->count() / $total * 100, 2) : 0.0,
            'avg_saved_bps' => $bps->isNotEmpty() ? round($bps->avg(), 2) : '',
            'total_saved_usd' => round((float) $filled->sum('saved_usd'), 2),
            'avg_miss_move_pct' => $expired->isNotEmpty() ? round((float) $expired->avg('miss_move_pct'), 4) : '',
        ];
    }
}
