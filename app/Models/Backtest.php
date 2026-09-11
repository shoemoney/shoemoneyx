<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TruncatesError;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Backtest extends Model
{
    use TruncatesError;

    protected $guarded = [];

    protected $appends = ['strategy_version'];

    protected function casts(): array
    {
        return [
            'products' => 'array', 'params' => 'array', 'stats' => 'array',
            'equity_curve' => 'array', 'trades' => 'array',
            'from' => 'datetime', 'to' => 'datetime',
            'starting_cash' => 'float', 'ending_equity' => 'float',
            'computed_at' => 'datetime', 'completed_at' => 'datetime', 'payload_stripped_at' => 'datetime',
        ];
    }

    public function strategyPluginVersion(): BelongsTo
    {
        return $this->belongsTo(StrategyPluginVersion::class);
    }

    /** Semver string of the pinned version, or null when this run isn't a JSON-plugin backtest. */
    protected function strategyVersion(): Attribute
    {
        return Attribute::get(fn () => $this->strategyPluginVersion?->version);
    }

    /**
     * Identity of a backtest for the optimizer's result cache: same strategy, coin(s), window,
     * starting cash and strategy params (the `_opt`/`_sim` bookkeeping keys excluded, since they
     * carry per-candidate identifiers that would make every row unique). Canonical JSON — keys
     * ksorted recursively, dates as ISO strings, products sorted — so key order never matters.
     */
    public static function cacheKeyFor(string $strategy, array $products, $from, $to, float $startingCash, array $params): string
    {
        sort($products);
        $canonical = [
            'strategy' => $strategy,
            'products' => $products,
            'from' => Carbon::parse($from)->toIso8601String(),
            'to' => Carbon::parse($to)->toIso8601String(),
            'starting_cash' => $startingCash,
            'params' => self::ksortRecursive(self::stripVolatileKeysRecursive($params)),
        ];

        return hash('sha256', json_encode($canonical));
    }

    /**
     * `_opt` (candidate id, round batch UUID, tag) and `_sim` are per-run bookkeeping, not identity —
     * two rounds sweeping identical params under different batch UUIDs must produce the same cache
     * key. Backtester::paramsFor() mirrors every non-`per_product.` override onto
     * `per_product.<pid>.*` too, so `_opt`/`_sim` reappear at that nesting level as well; strip them
     * wherever they occur, not just at the top.
     */
    private static function stripVolatileKeysRecursive(array $params): array
    {
        unset($params['_opt'], $params['_sim']);
        foreach ($params as $k => $v) {
            if (is_array($v)) {
                $params[$k] = self::stripVolatileKeysRecursive($v);
            }
        }

        return $params;
    }

    private static function ksortRecursive(array $a): array
    {
        ksort($a);
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $a[$k] = self::ksortRecursive($v);
            }
        }

        return $a;
    }
}
