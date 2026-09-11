<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The agent's verdict on one backtest of one pinned plugin version. */
class StrategyReview extends Model
{
    public $timestamps = false;

    protected $fillable = ['plugin_id', 'version_id', 'backtest_id', 'model', 'verdict', 'notes', 'suggestions', 'created_at'];

    protected function casts(): array
    {
        return [
            'suggestions' => 'array',
            'created_at' => 'datetime',
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

    public function backtest(): BelongsTo
    {
        return $this->belongsTo(Backtest::class, 'backtest_id');
    }
}
