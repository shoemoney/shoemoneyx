<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable snapshot of a strategy plugin's definition. Created on every
 * save (including restores) through StrategyPluginController /
 * StrategyPluginVersionController — never updated or deleted once written,
 * so a backtest that pins a version_id keeps reading exactly the definition
 * it ran against no matter how the plugin is edited afterward.
 */
class StrategyPluginVersion extends Model
{
    protected $fillable = ['strategy_plugin_id', 'version', 'definition', 'changelog', 'created_by', 'published_at'];

    protected $casts = [
        'definition' => 'array',
        'published_at' => 'datetime',
    ];

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(StrategyPlugin::class, 'strategy_plugin_id');
    }
}
