<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Candidate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metrics' => 'array',
            'context' => 'array',
            'checks_run' => 'array',
            'checks_skipped' => 'array',
            'evidence' => 'array',
            'degraded' => 'boolean',
            'exitable' => 'boolean',
            'ceiling_applied' => 'boolean',
            'score' => 'float',
            'size_usd' => 'float',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DeskRun::class, 'desk_run_id');
    }
}
