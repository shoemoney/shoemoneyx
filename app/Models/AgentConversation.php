<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentConversation extends Model
{
    protected $fillable = ['user_id', 'plugin_id', 'messages', 'phase'];

    protected $casts = ['messages' => 'array', 'phase' => 'integer'];

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(StrategyPlugin::class, 'plugin_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
