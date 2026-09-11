<?php

declare(strict_types=1);

namespace App\Hub;

use App\Desk\Execution\PaperExecutor;
use App\Exchange\Contracts\MarketData;
use App\Models\ArenaSeat;
use App\Models\Fill;
use App\Models\Position;
use Illuminate\Support\Facades\Log;

/**
 * Paper trading stays local (docs/HUB_API.md): a contest entry's arena seat trades exactly like
 * any other seat, through the normal PaperExecutor. This is the other half — it reports that
 * seat's already-executed fills and a throttled equity snapshot to the hub so the leaderboard has
 * something to rank. It never sends orders and never reads back cash/positions from the hub.
 */
final class ContestReporter
{
    public function __construct(
        private readonly HubClient $client,
        private readonly PaperExecutor $paper,
        private readonly MarketData $market,
    ) {}

    /** Reports unsent fills then, if the once-a-minute throttle allows it, a snapshot. Silently returns for a withdrawn/settled or seat-less entry. */
    public function report(ContestEntry $entry): void
    {
        if ($entry->state !== 'live' || $entry->arena_seat_id === null) {
            return;
        }

        $seat = $entry->seat ?? ArenaSeat::find($entry->arena_seat_id);
        if ($seat === null) {
            return;
        }

        try {
            $this->reportFills($entry, $seat);
            $this->reportSnapshot($entry, $seat);
        } catch (HubException $e) {
            // A quiet hub or a network blip shouldn't crash the scheduled command for every other
            // live entry — the bookmarks (last_reported_fill_id/last_snapshot_at) only advance on
            // success, so the next run just retries from where this one left off.
            Log::warning('contest report failed', ['contest_slug' => $entry->contest_slug, 'entry_id' => $entry->id, 'code' => $e->code, 'message' => $e->getMessage()]);
        }
    }

    private function reportFills(ContestEntry $entry, ArenaSeat $seat): void
    {
        $fills = Fill::where('arena_seat_id', $seat->id)
            ->where('status', 'filled')
            ->when($entry->last_reported_fill_id, fn ($q, $sinceId) => $q->where('id', '>', $sinceId))
            ->orderBy('id')
            ->get();

        if ($fills->isEmpty()) {
            return;
        }

        $payload = $fills->map(fn (Fill $fill) => array_filter([
            'client_id' => "seat{$seat->id}-fill{$fill->id}",
            'product_id' => $fill->product_id,
            'side' => strtolower((string) $fill->side),
            'size' => $fill->filled_qty,
            'price' => $fill->fill_price,
            'fee_usd' => $fill->fee_usd,
            'at' => $fill->created_at?->toIso8601String(),
            'position_id' => $fill->position_id,
            'pnl_usd' => $fill->position?->pnl_usd,
        ], fn ($value) => $value !== null))->values()->all();

        $this->client->pushFills($entry->contest_slug, $payload);

        $entry->update(['last_reported_fill_id' => $fills->last()->id]);
    }

    private function reportSnapshot(ContestEntry $entry, ArenaSeat $seat): void
    {
        if ($entry->last_snapshot_at !== null && $entry->last_snapshot_at->diffInSeconds(now()) < 60) {
            return;
        }

        $executor = $this->paper->withSeat($seat->id);
        $open = Position::open()->mode('paper')->seat($seat->id)->get();
        $priceOf = fn (Position $p) => (float) ($this->market->price($p->product_id) ?? $p->last_price ?? $p->entry_price ?? 0.0);

        $cash = $executor->cash();
        $equity = $cash + $open->sum(fn (Position $p) => $p->marketValue($priceOf($p)));

        $this->client->pushSnapshot($entry->contest_slug, [
            'equity' => round($equity, 4),
            'cash' => round($cash, 4),
            'open_positions' => $open->map(fn (Position $p) => [
                'product_id' => $p->product_id,
                'side' => $p->side,
                'size' => $p->quantity,
                'entry_price' => $p->entry_price,
                'mark' => $priceOf($p),
            ])->values()->all(),
            'at' => now()->toIso8601String(),
        ]);

        $entry->update(['last_snapshot_at' => now()]);
    }
}
