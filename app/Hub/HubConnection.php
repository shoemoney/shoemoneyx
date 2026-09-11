<?php

declare(strict_types=1);

namespace App\Hub;

use Illuminate\Database\Eloquent\Model;

/**
 * This desk's connection to the hosted community hub (shoemoneyx-hub). Single-user,
 * single-install: at most one row is meaningfully "active" at a time, so callers
 * never need to filter by a user id — see active().
 */
class HubConnection extends Model
{
    protected $fillable = [
        'user_handle', 'desk_id', 'token', 'connected_at', 'revoked_at',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'connected_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function active(): ?self
    {
        return self::query()
            ->whereNull('revoked_at')
            ->latest('id')
            ->first();
    }
}
