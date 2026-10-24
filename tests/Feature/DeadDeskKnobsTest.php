<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Settings;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeadDeskKnobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_no_longer_expose_unread_knobs(): void
    {
        $params = $this->getJson('/api/settings')->assertOk()->json('params');

        $this->assertIsArray($params);
        $this->assertNotEmpty($params);
        $this->assertArrayNotHasKey('scan.min_buy_sell_ratio', $params);
        $this->assertArrayNotHasKey('vet.min_rejections_per_day_warning', $params);
    }

    public function test_migration_drops_saved_overrides_for_the_retired_knobs(): void
    {
        foreach (['scan.min_buy_sell_ratio' => 1.5, 'vet.min_rejections_per_day_warning' => 3, 'size.kelly_cap_pct' => 7] as $key => $value) {
            Setting::create(['key' => $key, 'value' => $value]);
        }
        Settings::invalidate();

        $before = $this->getJson('/api/settings')->assertOk()->json('params');
        $this->assertArrayHasKey('scan.min_buy_sell_ratio', $before, 'a saved override resurfaces the retired knob');

        (require database_path('migrations/2026_10_06_000001_drop_retired_desk_knob_overrides.php'))->up();

        $keys = Setting::query()->pluck('key')->all();
        $this->assertNotContains('scan.min_buy_sell_ratio', $keys);
        $this->assertNotContains('vet.min_rejections_per_day_warning', $keys);
        $this->assertContains('size.kelly_cap_pct', $keys, 'a live override survives');

        $after = $this->getJson('/api/settings')->assertOk()->json('params');
        $this->assertArrayNotHasKey('scan.min_buy_sell_ratio', $after);
        $this->assertArrayNotHasKey('vet.min_rejections_per_day_warning', $after);
    }
}
