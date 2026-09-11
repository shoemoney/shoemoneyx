<?php

declare(strict_types=1);

namespace App\Desk\Execution;

use App\Desk\Reporter;
use App\Desk\Settings;
use App\Exchange\Contracts\MarketData;
use App\Models\Candle;
use App\Models\Fill;
use App\Models\PostOnlyShadow;
use App\Models\Position;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Paper-only A/B: for every real entry/add/trim fill, records the resting post-only limit a maker
 * order would have rested at and, on the resolver's own timer, decides from the sub-minute tape
 * whether it would have filled, chased once, or expired. Never touches the real fill/position/ledger.
 */
class PostOnlyShadows
{
    public function __construct(
        private Settings $settings,
        private MarketData $market,
        private Reporter $reporter,
    ) {}

    /** Entry/add: $limitPrice is null (derived from the fill's own best_bid/best_ask). Trim: the rung target, already crossed, so the shadow lands resolved. */
    public function record(Fill $fill, ?Position $position, string $kind, ?float $limitPrice): void
    {
        if (! $this->settings->get('post_only.shadow', false)) {
            return;
        }
        if ($fill->mode !== 'paper' || $fill->status !== 'filled' || (float) $fill->filled_qty <= 0) {
            return;
        }

        if ($kind === 'trim') {
            if ($limitPrice !== null) {
                $this->recordTrim($fill, $position, $limitPrice);
            }

            return;
        }

        $this->recordEntry($fill, $position, $kind);
    }

    private function recordEntry(Fill $fill, ?Position $position, string $kind): void
    {
        $raw = $fill->raw ?? [];
        $limitPrice = $fill->side === 'BUY' ? ($raw['best_bid'] ?? null) : ($raw['best_ask'] ?? null);
        if ($limitPrice === null || (float) $limitPrice <= 0) {
            return;   // no quote on the fill (a rejection or a legacy raw shape) — nothing to shadow
        }

        $placedAt = $fill->created_at ?? now();
        $ttl = (int) $this->settings->get('post_only.ttl_seconds', 300);

        PostOnlyShadow::create([
            'fill_id' => $fill->id,
            'position_id' => $position?->id,
            'mode' => 'paper',
            'product_id' => $fill->product_id,
            'side' => $fill->side,
            'kind' => $kind,
            'qty' => $fill->filled_qty,
            'actual_price' => $fill->fill_price,
            'actual_fee_usd' => $fill->fee_usd,
            'limit_price' => $limitPrice,
            'status' => 'resting',
            'chase_count' => 0,
            'placed_at' => $placedAt,
            'expires_at' => $placedAt->copy()->addSeconds($ttl),
            'meta' => ['bar_timeframe' => $this->settings->get('post_only.bar_timeframe', '13s'), 'quote_at_placement' => ['best_bid' => $raw['best_bid'] ?? null, 'best_ask' => $raw['best_ask'] ?? null]],
        ]);
    }

    private function recordTrim(Fill $fill, ?Position $position, float $limitPrice): void
    {
        $side = $fill->side;
        $qty = (float) $fill->filled_qty;
        $shadowFee = $this->makerFee($qty * $limitPrice);
        $placedAt = $fill->created_at ?? now();
        $ttl = (int) $this->settings->get('post_only.ttl_seconds', 300);
        $timeframe = (string) $this->settings->get('post_only.bar_timeframe', '13s');
        $bar = $this->barCovering($fill->product_id, $timeframe, $placedAt);

        PostOnlyShadow::create([
            'fill_id' => $fill->id,
            'position_id' => $position?->id,
            'mode' => 'paper',
            'product_id' => $fill->product_id,
            'side' => $side,
            'kind' => 'trim',
            'qty' => $qty,
            'actual_price' => $fill->fill_price,
            'actual_fee_usd' => $fill->fee_usd,
            'limit_price' => $limitPrice,
            'status' => 'filled',
            'chase_count' => 0,
            'placed_at' => $placedAt,
            'expires_at' => $placedAt->copy()->addSeconds($ttl),
            'resolved_at' => $placedAt,
            'shadow_price' => $limitPrice,
            'shadow_fee_usd' => $shadowFee,
            'saved_usd' => $this->savedUsd($side, (float) $fill->fill_price, $limitPrice, (float) $fill->fee_usd, $shadowFee, $qty),
            'meta' => [
                'bar_timeframe' => $timeframe,
                'touch_only' => $bar === null ? null : $this->touchOnly($side, $limitPrice, $bar),
            ],
        ]);
    }

    /** Runs from the DeskRun loop, at most every 15s. @return int shadows that changed state this pass */
    public function resolve(): int
    {
        if (! $this->settings->get('post_only.shadow', false)) {
            return 0;
        }

        $changed = 0;
        foreach (PostOnlyShadow::where('status', 'resting')->orderBy('placed_at')->get() as $shadow) {
            try {
                if ($this->resolveOne($shadow)) {
                    $changed++;
                }
            } catch (\Throwable $e) {
                $this->reporter->warn('POSTONLY', "resolve #{$shadow->id} {$shadow->product_id}: ".$e->getMessage());
            }
        }

        return $changed;
    }

    private function resolveOne(PostOnlyShadow $shadow): bool
    {
        $timeframe = (string) $this->settings->get('post_only.bar_timeframe', '13s');
        $since = $shadow->chase_count > 0 && isset($shadow->meta['chase']['at'])
            ? Carbon::parse($shadow->meta['chase']['at'])
            : $shadow->placed_at;

        $bars = Candle::for($shadow->product_id, $timeframe)->where('candle_start', '>=', $since)->orderBy('candle_start')->get();
        foreach ($bars as $bar) {
            $filled = $shadow->side === 'BUY' ? (float) $bar->low < (float) $shadow->limit_price : (float) $bar->high > (float) $shadow->limit_price;
            if ($filled) {
                $this->fillShadow($shadow, $bar);

                return true;
            }
        }

        if ((int) $shadow->chase_count === 0 && $this->chase($shadow)) {
            return true;
        }

        if (now()->greaterThanOrEqualTo($shadow->expires_at)) {
            $this->expire($shadow);

            return true;
        }

        return false;
    }

    private function fillShadow(PostOnlyShadow $shadow, Candle $bar): void
    {
        $limitPrice = (float) $shadow->limit_price;
        $qty = (float) $shadow->qty;
        $shadowFee = $this->makerFee($qty * $limitPrice);
        $meta = $shadow->meta ?? [];
        $meta['fill_bar'] = ['candle_start' => $bar->candle_start->toDateTimeString(), 'low' => $bar->low, 'high' => $bar->high];

        $shadow->update([
            'status' => 'filled',
            'shadow_price' => $limitPrice,
            'shadow_fee_usd' => $shadowFee,
            'saved_usd' => $this->savedUsd($shadow->side, (float) $shadow->actual_price, $limitPrice, (float) $shadow->actual_fee_usd, $shadowFee, $qty),
            'resolved_at' => $bar->candle_start,
            'meta' => $meta,
        ]);
    }

    /** Chases once when the current quote has run away by more than post_only.chase_pct. */
    private function chase(PostOnlyShadow $shadow): bool
    {
        $ticker = $this->market->ticker($shadow->product_id, 1);
        $bestBid = (float) ($ticker['best_bid'] ?? 0);
        $bestAsk = (float) ($ticker['best_ask'] ?? 0);
        $limitPrice = (float) $shadow->limit_price;
        $chasePct = (float) $this->settings->get('post_only.chase_pct', 0.05) / 100;

        $ranAway = $shadow->side === 'BUY'
            ? $bestBid > 0 && $bestBid > $limitPrice * (1 + $chasePct)
            : $bestAsk > 0 && $bestAsk < $limitPrice * (1 - $chasePct);

        if (! $ranAway) {
            return false;
        }

        $to = $shadow->side === 'BUY' ? $bestBid : $bestAsk;
        $meta = $shadow->meta ?? [];
        $meta['chase'] = ['from' => $limitPrice, 'to' => $to, 'at' => now()->toDateTimeString()];

        $shadow->update(['limit_price' => $to, 'chase_count' => 1, 'meta' => $meta]);

        return true;
    }

    private function expire(PostOnlyShadow $shadow): void
    {
        $ticker = $this->market->ticker($shadow->product_id, 1);
        $last = (float) ($shadow->side === 'BUY' ? ($ticker['best_bid'] ?? 0) : ($ticker['best_ask'] ?? 0));
        $limitPrice = (float) $shadow->limit_price;
        $missMovePct = $limitPrice > 0
            ? ($shadow->side === 'BUY' ? ($last - $limitPrice) / $limitPrice * 100 : ($limitPrice - $last) / $limitPrice * 100)
            : null;

        $meta = $shadow->meta ?? [];
        $meta['last_price'] = $last;

        $shadow->update(['status' => 'expired', 'resolved_at' => now(), 'miss_move_pct' => $missMovePct, 'meta' => $meta]);
    }

    /** The bar (post_only.bar_timeframe) covering $at, or null when none is stored yet. */
    private function barCovering(string $productId, string $timeframe, CarbonInterface $at): ?Candle
    {
        return Candle::for($productId, $timeframe)->where('candle_start', '<=', $at)->orderByDesc('candle_start')->first();
    }

    /** True when the bar's extreme merely equalled the rung target rather than trading through it. */
    private function touchOnly(string $side, float $target, Candle $bar): bool
    {
        $extreme = $side === 'SELL' ? (float) $bar->high : (float) $bar->low;

        return abs($extreme - $target) < 1e-9;
    }

    private function savedUsd(string $side, float $actualPrice, float $shadowPrice, float $actualFee, float $shadowFee, float $qty): float
    {
        return $side === 'BUY'
            ? ($actualPrice - $shadowPrice) * $qty + ($actualFee - $shadowFee)
            : ($shadowPrice - $actualPrice) * $qty + ($actualFee - $shadowFee);
    }

    private function makerFee(float $notional): float
    {
        $rate = (float) $this->settings->get('fees.maker_rate', 0.004);
        $perContract = (float) $this->settings->get('fees.per_contract_usd', 0);

        return Lot::fee($notional, 0, $rate, $perContract);
    }
}
