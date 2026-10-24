<?php

declare(strict_types=1);

namespace App\Desk;

use App\Desk\Contracts\Strategy;
use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        return self::isCanonicalKey($key)
            && ($key === 'master_password' || ! self::isSecret($key))
            && ! self::isUnderScalarKey($key);
    }

    /** Top-level settings read as one plain value; nothing may be nested beneath them. */
    public const SCALAR_KEYS = ['mode', 'strategy', 'exchange', 'live_confirm', 'use_scheduler', 'cycle_budget_seconds'];

    public static function isScalarKey(string $key): bool
    {
        return in_array($key, self::SCALAR_KEYS, true);
    }

    /** True for mode.x and the like: a descendant would turn the scalar into an array. */
    public static function isUnderScalarKey(string $key): bool
    {
        foreach (self::SCALAR_KEYS as $scalar) {
            if (str_starts_with($key, $scalar.'.')) {
                return true;
            }
        }

        return false;
    }

    /** Keys the desk reads as a string; an array here would break string casts in health checks. */
    public const STRING_KEYS = ['worldmonitor.base_url'];

    /** Why $value may not be written at $key, or null. Covers the key itself, a parent map and a nested child. */
    public static function stringKeyViolation(string $key, mixed $value): ?string
    {
        $key = strtolower($key);   // utf8mb4_unicode_ci would map worldmonitor.Base_URL onto the same row
        foreach (self::STRING_KEYS as $stringKey) {
            if (str_starts_with($key, $stringKey.'.')) {
                return "{$key}: nothing may be nested under {$stringKey}";
            }
            if ($key === $stringKey && ! is_string($value)) {
                return "{$key} must be a string";
            }
            if (str_starts_with($stringKey, $key.'.') && is_array($value)) {
                $leaf = Arr::get($value, substr($stringKey, strlen($key) + 1));
                if ($leaf !== null && ! is_string($leaf)) {
                    return "{$stringKey} must be a string";
                }
            } elseif (str_starts_with($stringKey, $key.'.') && ! is_array($value)) {
                return "{$key} must be a map";
            }
        }

        return null;
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
        return $this->scalarString('mode', 'paper');
    }

    /**
     * The password the owner chose during setup, stored only as a Hash::make() hash. Null until
     * they set one (an empty or malformed row counts as unset). The .env MASTER_PASSWORD is not
     * this: it is a one-time bootstrap key, see bootstrapKey().
     */
    private function storedPasswordHash(): ?string
    {
        try {
            // Authentication must observe the current row, not the generic 30-second settings
            // cache: a concurrent reader can refill that cache just after a password change.
            $stored = $this->storedPasswordValue();

            return is_string($stored) && $stored !== '' ? $stored : null;
        } catch (\Throwable $e) {
            // A failed read is not "no password set": that would let the bootstrap key in again.
            report($e);

            throw new HttpException(503, 'settings unavailable, try again shortly');
        }
    }

    protected function storedPasswordValue(): mixed
    {
        $row = Setting::query()->find('master_password');

        return $row?->key === 'master_password' ? $row->value : null;
    }

    /** MASTER_PASSWORD from .env: the EC2 instance ID on AMIs, the generated value from docker/up.sh. */
    private function bootstrapKey(): string
    {
        return (string) config('desk.master_password', '');
    }

    /** True when any credential can open the desk: the owner's password or a bootstrap key. */
    public function hasMasterPassword(): bool
    {
        return $this->storedPasswordHash() !== null || $this->bootstrapKey() !== '';
    }

    /**
     * True while the .env bootstrap key is the only credential (the owner has not chosen a password
     * yet). Drives the login hint; sessions that signed in with it are limited to the set-password screen.
     */
    public function masterPasswordIsBootstrap(): bool
    {
        return $this->storedPasswordHash() === null && $this->bootstrapKey() !== '';
    }

    /** True until the owner has chosen their own password, whether or not a bootstrap key exists. */
    public function needsPasswordSetup(): bool
    {
        return $this->storedPasswordHash() === null;
    }

    public function verifyMasterPassword(string $candidate): bool
    {
        $hash = $this->storedPasswordHash();
        if ($hash === null) {
            return $this->isBootstrapKey($candidate);
        }

        return $this->checkPasswordHash($candidate, $hash);
    }

    private function checkPasswordHash(string $candidate, string $hash): bool
    {
        // bcrypt ignores bytes after 72. Setup enforces this same limit, so accepting a longer
        // candidate here would let an appended suffix authenticate as an exact-length password.
        if (strlen($candidate) > 72) {
            return false;
        }

        try {
            return Hash::check($candidate, $hash);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * True when $candidate is the .env bootstrap key, which buyers paste by hand from a console
     * column: surrounding whitespace and letter case are ignored (an instance ID is lowercase
     * hex), still compared in constant time. Only this key is forgiving; the owner's own password
     * is always exact. The owner's password may never equal it.
     */
    public function isBootstrapKey(string $candidate): bool
    {
        $bootstrap = strtolower(trim($this->bootstrapKey()));

        return $bootstrap !== '' && hash_equals($bootstrap, strtolower(trim($candidate)));
    }

    /**
     * verifyMasterPassword for the X-Desk-Token header, which arrives on every script request. A
     * bcrypt check costs tens of milliseconds, so a success is remembered for a minute under a key
     * keyed (HMAC with the app key, so a leaked cache cannot be used to test guesses offline) on the token and the current credential (the token itself is never stored, and a
     * password change makes the old key unreachable).
     */
    public function verifyToken(string $token): bool
    {
        // One fresh snapshot supplies both the verification and its cache identity. Re-reading
        // the same row here adds queries and can pair a verification with a different credential.
        $hash = $this->storedPasswordHash();
        if ($hash === null) {
            return $this->isBootstrapKey($token);
        }

        // Check before the success cache too: a token accepted by an older build may still have
        // a cached success during an upgrade.
        if (strlen($token) > 72) {
            return false;
        }

        $key = 'desk:token-ok:'.hash_hmac('sha256', $token."\0".$hash, (string) config('app.key'));
        if (Cache::get($key) === true) {
            return true;
        }
        if (! $this->checkPasswordHash($token, $hash)) {
            return false;
        }
        Cache::put($key, true, 60);

        return true;
    }

    /**
     * Stable string that changes whenever the credential does: the stored hash, else the bootstrap
     * key, else empty (nothing can authenticate). DeskLogin keys its session fingerprint on it.
     */
    public function credentialFingerprintSource(): string
    {
        return $this->storedPasswordHash() ?? $this->bootstrapKey();
    }

    public function setMasterPassword(string $password): void
    {
        $this->set('master_password', Hash::make($password));
    }

    /** Commit only if the credential authorized by this request still owns the desk. */
    public function setMasterPasswordIfUnchanged(string $password, string $expectedCredential): ?string
    {
        $newHash = Hash::make($password);

        // Atomic first-owner claim, without a read-then-insert transaction. This avoids both
        // InnoDB missing-row gap-lock deadlocks and SQLite read-lock upgrade failures.
        if (hash_equals($expectedCredential, $this->bootstrapKey()) && Setting::query()->insertOrIgnore([
            'key' => 'master_password', 'value' => json_encode($newHash),
            'created_at' => now(), 'updated_at' => now(),
        ]) === 1) {
            Cache::forget(self::CACHE_KEY);

            return $newHash;
        }

        // SQLite cannot upgrade both concurrent readers to writers. Retry classified lock/
        // serialization failures from a fresh transaction, then recheck the winning credential.
        // Unexpected database failures still propagate; exhausted retries are never success.
        $changed = DB::transaction(function () use ($newHash, $expectedCredential): bool {
            $row = Setting::query()->whereKey('master_password')->lockForUpdate()->first();
            if ($row === null || $row->key !== 'master_password') {
                return false;
            }
            $value = $row->value;
            $current = is_string($value) && $value !== '' ? $value : $this->bootstrapKey();
            if (! hash_equals($expectedCredential, $current)) {
                return false;
            }

            $row->update(['value' => $newHash]);

            return true;
        }, 5);

        if ($changed) {
            Cache::forget(self::CACHE_KEY);
        }

        return $changed ? $newHash : null;
    }

    /** A stored non-scalar value (legacy array rows) falls back to the config default instead of crashing. */
    private function scalarString(string $key, string $default): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : (string) config('desk.'.$key, $default);
    }

    public function strategyKey(): string
    {
        return $this->scalarString('strategy', 'mr');
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
