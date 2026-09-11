<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One encrypted credential bag per ccxt exchange id. Coinbase keeps its own table. */
class ExchangeCredential extends Model
{
    protected $fillable = ['exchange', 'values'];

    protected $hidden = ['values'];

    protected function casts(): array
    {
        return [
            'values' => 'encrypted:array',
        ];
    }
}
