<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One audited model call: who, which model, how it ended, what it cost. Prompts
 * and completions are deliberately absent — App\Ai\Gate never receives them.
 */
class AiCall extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'model', 'prompt_tokens', 'completion_tokens',
        'cost_usd', 'status', 'error', 'duration_ms', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'cost_usd' => 'float',
            'created_at' => 'datetime',
        ];
    }
}
