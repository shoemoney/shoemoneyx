<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class DeskEvent extends Model
{
    use MassPrunable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<=', now()->subDays(max(1, (int) config('desk.retention.events_days', 30))));
    }
}
