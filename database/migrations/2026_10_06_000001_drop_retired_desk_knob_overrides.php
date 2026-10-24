<?php

use App\Desk\Settings;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

// scan.min_buy_sell_ratio and vet.min_rejections_per_day_warning left config/desk.php because
// nothing ever read them. Settings::overrides() merges every storable row, so a desk that once
// saved either one would keep showing it as a knob; drop exactly those two rows.
return new class extends Migration
{
    private const RETIRED = ['scan.min_buy_sell_ratio', 'vet.min_rejections_per_day_warning'];

    public function up(): void
    {
        Setting::query()->whereIn('key', self::RETIRED)->delete();
        Settings::invalidate();
    }

    public function down(): void
    {
        // The keys no longer exist and nothing reads them; there is nothing to restore.
    }
};
