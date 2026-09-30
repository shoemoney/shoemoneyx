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

    /** Keys the generic settings API never returns or edits. */
    public const SECRET_KEYS = ['master_password'];

    /**
     * Keys the settings API accepts. MariaDB compares settings.key under utf8mb4_unicode_ci, which
     * ignores case, accents and trailing spaces, so MASTER_PASSWORD or "mäster_password " would
     * land on the master_password row. A lowercase ASCII first segment closes that; later
     * segments may carry product ids such as perps.map.BTC-USD.
     */
    public static function isCanonicalKey(string $key): bool
    {
        return preg_match('/\A[a-z0-9][a-z0-9_-]*(\.[A-Za-z0-9_-]+)*\z/', $key) === 1;
    }

    /** True for a secret key or any dotted key beneath one (master_password.x would shadow it). */
    public static function isSecret(string $key): bool
    {
        foreach (self::SECRET_KEYS as $secret) {
            if ($key === $secret || str_starts_with($key, $secret.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rows overrides() may merge. Before v0.2.2 the settings API took any key, so a desk can hold
     * master_password.x (Arr::set turns the password into an array) or a case/space alias of a
     * real key; those are skipped here and purged by a migration.
     */
    public static function isStorableKey(string $key): bool
    {
        return self::isCanonicalKey($key) && ($key === 'master_password' || ! self::isSecret($key));
    }

    public function overrides(): array
    {
        return Cache::remember(self::CACHE_KEY, 30, function () {
            $out = [];
            foreach (Setting::all() as $row) {
                if (! self::isStorableKey($row->key)) {
                    continue;
                }
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
            $password = $this->get('master_password', '');

            return is_array($password) ? (string) config('desk.master_password', '') : (string) $password;
        } catch (\Throwable) {
            return (string) config('desk.master_password', '');
        }
    }

    /**
     * True when the effective master password is still the .env value nobody has overridden
     * from onboarding/Settings yet ("bootstrap" password) — false once an override row exists,
     * regardless of what it's set to (including an explicit empty override). Drives the login
     * page hint and the onboarding "you signed in with the bootstrap password" nudge. Fails
     * quiet to false on a DB error, same posture as masterPassword().
     */
    public function masterPasswordIsBootstrap(): bool
    {
        try {
            return ! Arr::has($this->overrides(), 'master_password');
        } catch (\Throwable) {
            return false;
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

        return Arr::except(array_replace_recursive($base, $strategy->defaults(), $this->overrides()), self::SECRET_KEYS);
    }

    /** @return array<string, mixed> flattened dotted view of merged params (for the settings UI) */
    public function flattened(Strategy $strategy): array
    {
        return Arr::dot($this->merged($strategy));
    }
}
