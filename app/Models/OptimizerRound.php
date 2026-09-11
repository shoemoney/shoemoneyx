<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OptimizerRound extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'train_from' => 'datetime', 'train_to' => 'datetime', 'test_from' => 'datetime', 'test_to' => 'datetime',
            'champion_params' => 'array', 'best_params' => 'array', 'promoted' => 'boolean',
        ];
    }
}
