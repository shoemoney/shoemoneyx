<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TruncatesError;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeskRun extends Model
{
    use TruncatesError;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'degraded' => 'boolean',
            'stats' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class)->orderBy('rank');
    }

    public function fills(): HasMany
    {
        return $this->hasMany(Fill::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeskEvent::class);
    }
}
