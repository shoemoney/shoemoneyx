<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class CoinbaseApiLog extends Model
{
    use Prunable;

    protected $guarded = [];

    protected $hidden = ['request_body', 'response_body'];

    protected function casts(): array
    {
        return ['request_body' => 'array', 'response_body' => 'array'];
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<=', now()->subDays(7));
    }
}
