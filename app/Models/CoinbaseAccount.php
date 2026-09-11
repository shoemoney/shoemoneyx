<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CoinbaseAccount extends Model
{
    protected $fillable = ['name', 'api_key_name', 'api_private_key', 'is_active', 'last_used_at'];

    protected $hidden = ['api_key_name', 'api_private_key'];

    protected function casts(): array
    {
        return [
            'api_key_name' => 'encrypted',
            'api_private_key' => 'encrypted',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function apiLogs(): HasMany
    {
        return $this->hasMany(CoinbaseApiLog::class);
    }

    public static function active(): ?self
    {
        return static::where('is_active', true)->orderByDesc('id')->first();
    }
}
