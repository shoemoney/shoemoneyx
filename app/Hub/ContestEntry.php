<?php

declare(strict_types=1);

namespace App\Hub;

use App\Models\ArenaSeat;
use App\Models\StrategyPlugin;
use App\Models\StrategyPluginVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A local record of this desk's entry into a hub contest — one row per plugin version entered.
 * Paper trading stays local: the entry's `arena_seat_id` is the seat that actually trades it,
 * and `last_reported_fill_id`/`last_snapshot_at` are ContestReporter's bookmarks for what it has
 * already pushed to the hub.
 */
class ContestEntry extends Model
{
    protected $fillable = [
        'contest_slug', 'plugin_id', 'version_id', 'entry_id', 'paper_account_id', 'state',
        'arena_seat_id', 'last_reported_fill_id', 'last_snapshot_at',
    ];

    protected function casts(): array
    {
        return [
            'last_snapshot_at' => 'datetime',
        ];
    }

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(StrategyPlugin::class, 'plugin_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(StrategyPluginVersion::class, 'version_id');
    }

    public function seat(): BelongsTo
    {
        return $this->belongsTo(ArenaSeat::class, 'arena_seat_id');
    }
}
