<?php

declare(strict_types=1);

namespace App\Desk;

use App\Desk\Contracts\Strategy;
use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Layered configuration: config/desk.php <- strategy defaults <- settings table.
 * The dashboard writes overrides with Settings::set('size.kelly_cap_pct', 0.04).
 */
class Settings
{
    private const CACHE_KEY = 'desk:settings';

    public function overrides(): array
    {
        return Cache::remember(self::CACHE_KEY, 30, function () {
            $out = [];
            foreach (Setting::all() as $row) {
                Arr::set($out, $row->key, $row->value);
            }

            return $out;
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->overrides(), $key, config('desk.'.$key, $default));
    }

    public function set(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_KEY);
    }

    /** Invalidate the merged-settings cache without writing anything — for callers (DeskOptimize's
     *  compare-and-swap publish) that write several keys in one DB transaction and want exactly one
     *  cache invalidation, after commit, instead of one per key. */
    public static function invalidate(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function forget(string $key): void
    {
        Setting::where('key', $key)->delete();
        Cache::forget(self::CACHE_KEY);
    }

    /** Remove a key and everything under it (per_product.BTC-USD → all of that coin's overrides). */
    public function forgetTree(string $prefix): void
    {
        Setting::where('key', $prefix)->orWhere('key', 'like', $prefix.'.%')->delete();
        Cache::forget(self::CACHE_KEY);
    }

    public function mode(): string
    {
        return (string) $this->get('mode', 'paper');
    }

    /**
     * The effective master password: an onboarding-set override if one exists, else
     * MASTER_PASSWORD from .env. The single source every gate (MasterPassword, DeskToken,
     * DeskAuthController, app.blade.php) should read instead of config('desk.master_password')
     * directly — it is how the onboarding wizard can set a password without an env edit.
     * Falls back to the env value on a DB failure so the page shell (public demo included)
     * never 500s over an unreachable settings table.
     */
    public function masterPassword(): string
    {
        try {
            return (string) $this->get('master_password', '');
        } catch (\Throwable) {
            return (string) config('desk.master_password', '');
        }
    }

    public function strategyKey(): string
    {
        return (string) $this->get('strategy', 'mr');
    }

    /** Full merged parameter tree for a strategy. */
    public function merged(Strategy $strategy): array
    {
        $base = config('desk');
        unset($base['strategies'], $base['telegram']);

        return array_replace_recursive($base, $strategy->defaults(), $this->overrides());
    }

    /** @return array<string, mixed> flattened dotted view of merged params (for the settings UI) */
    public function flattened(Strategy $strategy): array
    {
        return Arr::dot($this->merged($strategy));
    }
}
