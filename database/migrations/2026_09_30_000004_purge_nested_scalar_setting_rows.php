<?php

use App\Desk\Settings;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

// PUT /api/settings used to accept mode.x, which turned the single-value mode into an array.
return new class extends Migration
{
    public function up(): void
    {
        Setting::query()->pluck('key')
            ->filter(fn (string $key) => Settings::isUnderScalarKey($key))
            ->each(fn (string $key) => Setting::query()->where('key', $key)->delete());

        foreach (Settings::SCALAR_KEYS as $key) {
            $row = Setting::query()->find($key);
            if ($row !== null && is_array($row->value)) {
                $row->delete();
            }
        }

        Cache::forget('desk:settings');
    }

    public function down(): void
    {
        // Purged rows were never valid settings.
    }
};
