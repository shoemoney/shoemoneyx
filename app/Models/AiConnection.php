<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One model-provider API key per desk operator, obtained through OAuth PKCE so
 * nobody ever pastes a key into the browser. `revoked_at` retires a connection;
 * `suspended_until` is the abuse cutoff App\Ai\Gate applies after repeated errors.
 */
class AiConnection extends Model
{
    protected $fillable = [
        'user_id', 'provider', 'key', 'label',
        'connected_at', 'last_used_at', 'revoked_at', 'suspended_until',
    ];

    protected $hidden = ['key'];

    protected function casts(): array
    {
        return [
            'key' => 'encrypted',
            'connected_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'suspended_until' => 'datetime',
        ];
    }

    public static function activeFor(string $userKey, string $provider = 'openrouter'): ?self
    {
        return self::query()
            ->where('user_id', $userKey)
            ->where('provider', $provider)
            ->whereNull('revoked_at')
            ->latest('id')
            ->first();
    }
}
