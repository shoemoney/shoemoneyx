<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostOnlyShadow extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qty' => 'float', 'actual_price' => 'float', 'actual_fee_usd' => 'float',
            'limit_price' => 'float', 'chase_count' => 'integer',
            'shadow_price' => 'float', 'shadow_fee_usd' => 'float', 'saved_usd' => 'float', 'miss_move_pct' => 'float',
            'meta' => 'array', 'placed_at' => 'datetime', 'expires_at' => 'datetime', 'resolved_at' => 'datetime',
        ];
    }

    public function shadowedFill(): BelongsTo
    {
        return $this->belongsTo(Fill::class, 'fill_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }
}
