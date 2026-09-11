<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankSnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cash' => 'float', 'positions_value' => 'float', 'equity' => 'float', 'locked' => 'float',
            'free_cash' => 'float', 'realised_pnl_today' => 'float', 'taken_at' => 'datetime',
            'collateral' => 'float', 'unrealised_pnl' => 'float', 'exposure' => 'float', 'buying_power' => 'float',
        ];
    }
}
