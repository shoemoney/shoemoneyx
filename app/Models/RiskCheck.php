<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiskCheck extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'volume_6h' => 'float', 'avg_6h' => 'float', 'ratio' => 'float', 'price' => 'float',
            'pnl_usd' => 'float', 'pnl_pct' => 'float', 'meta' => 'array',
        ];
    }
}
