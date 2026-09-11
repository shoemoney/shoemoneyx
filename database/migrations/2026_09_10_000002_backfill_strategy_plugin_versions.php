<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Every strategy_plugins row that predates versioning gets a 1.0.0 version row from its current definition. */
    public function up(): void
    {
        $now = now();
        foreach (DB::table('strategy_plugins')->select(['id', 'definition'])->cursor() as $plugin) {
            DB::table('strategy_plugin_versions')->insertOrIgnore([
                'strategy_plugin_id' => $plugin->id,
                'version' => '1.0.0',
                'definition' => $plugin->definition,
                'changelog' => null,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('strategy_plugins')->where('id', $plugin->id)->update(['current_version' => '1.0.0']);
        }
    }

    public function down(): void
    {
        // Backfilled data is left in place — the versions table drop in the companion
        // migration's down() already removes it.
    }
};
