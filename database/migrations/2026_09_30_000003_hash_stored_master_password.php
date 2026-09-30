<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

// Until now an owner-set master password was stored as plaintext in the settings table. Hash it;
// an empty override (the retired "no password" choice) is dropped so the desk fails closed and
// asks for a password instead of treating the blank as a credential.
return new class extends Migration
{
    public function up(): void
    {
        $row = Setting::query()->find('master_password');
        if ($row === null) {
            return;
        }

        $value = $row->value;
        if (! is_string($value) || $value === '') {
            $row->delete();
        } elseif (password_get_info($value)['algoName'] === 'unknown') {
            $row->update(['value' => Hash::make($value)]);
        }

        Cache::forget('desk:settings');
    }

    public function down(): void
    {
        // A hash cannot be turned back into the password it came from.
    }
};
