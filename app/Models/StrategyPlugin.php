<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StrategyPlugin extends Model
{
    protected $fillable = ['key', 'name', 'description', 'definition', 'current_version', 'auto_backtest', 'hub_slug'];

    protected $casts = [
        'definition' => 'array',
        'auto_backtest' => 'boolean',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(StrategyPluginVersion::class);
    }
}
