<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoinbaseApiLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['request_body' => 'array', 'response_body' => 'array'];
    }
}
