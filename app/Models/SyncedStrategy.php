<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bookkeeping row for one strategy tracked in the community strategies repo's
 * manifest.json (`remote_id` is the manifest entry's `id`). Written by
 * App\Strategies\Sync\StrategySync on every check() (remote_version/sha256/
 * seen_at) and import() (strategy_plugin_id/imported_at/local_definition_sha256).
 */
class SyncedStrategy extends Model
{
    protected $fillable = [
        'remote_id', 'remote_version', 'sha256', 'strategy_plugin_id',
        'imported_at', 'local_definition_sha256', 'seen_at',
    ];

    protected $casts = [
        'imported_at' => 'datetime',
        'seen_at' => 'datetime',
    ];

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(StrategyPlugin::class, 'strategy_plugin_id');
    }
}
