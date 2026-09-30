<?php

use App\Desk\Settings;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

// Up to v0.2.1 PUT /api/settings accepted any key, so a desk may hold rows such as
// master_password.x or MASTER_PASSWORD that shadow or alias the master password.
return new class extends Migration
{
    public function up(): void
    {
        Setting::query()->pluck('key')
            ->reject(fn (string $key) => Settings::isStorableKey($key))
            ->each(fn (string $key) => Setting::query()->where('key', $key)->delete());
    }

    public function down(): void
    {
        // Purged rows were never valid settings; nothing to restore.
    }
};
