<?php

declare(strict_types=1);

namespace App\Desk;

use App\Exchange\Contracts\Exchange;
use App\Exchange\Contracts\MarketData;
use App\Services\Market\LiveFeed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

/**
 * CHIEF runs the room and never takes a position. Heartbeats, health checks,
 * the halt switch. State lives in Redis so every process sees the same room.
 */
class Chief
{
    public const AGENTS = ['SCAN', 'VET', 'SIZE', 'FILLS', 'RISK'];

    public function __construct(
        private MarketData $market,
        private Reporter $reporter,
        private Settings $settings,
        private Exchange $exchange,
    ) {}

    public function heartbeat(string $agent): void
    {
        Cache::put("desk:hb:{$agent}", time(), 3600);
    }

    /** @return array<string, int|null> seconds since each agent's last heartbeat */
    public function heartbeats(): array
    {
        $out = [];
        foreach (self::AGENTS as $a) {
            $t = Cache::get("desk:hb:{$a}");
            $out[$a] = $t ? time() - (int) $t : null;
        }

        return $out;
    }

    public function halt(string $reason): void
    {
        Cache::forever('desk:halted', ['reason' => $reason, 'at' => now()->toIso8601String()]);
        $this->reporter->error('CHIEF', "DESK HALTED: {$reason}");
    }

    public function resume(): void
    {
        Cache::forget('desk:halted');
        $this->reporter->info('CHIEF', 'desk resumed');
    }

    public function halted(): ?array
    {
        $h = Cache::get('desk:halted');

        return is_array($h) ? $h : null;
    }

    public function running(): bool
    {
        return (bool) Cache::get('desk:running', false);
    }

    public function setRunning(bool $on): void
    {
        Cache::forever('desk:running', $on);
        $this->reporter->info('CHIEF', $on ? 'desk started' : 'desk stopped');
    }

    /** Three green checks or nothing is READY. */
    public function health(): array
    {
        $checks = [];

        $checks['database'] = $this->try(fn () => DB::select('select 1') !== null);
        $checks['redis'] = $this->try(function () {
            if (config('cache.default') !== 'redis' && config('queue.default') !== 'redis') {
                return Cache::put('desk:ping', 1, 10) && Cache::get('desk:ping') === 1;
            }
            Redis::set('desk:ping', '1', 'EX', 10);

            return Redis::get('desk:ping') === '1';
        });
        $checks['coinbase_public'] = $this->try(fn () => $this->market->healthy());
        $checks['candles_fresh'] = $this->try(function () {
            $latest = DB::table('candles')->where('timeframe', '1H')->max('candle_start');

            return $latest !== null && strtotime((string) $latest) >= time() - 3 * 3600;
        });

        $checks['websocket_feed'] = $this->try(fn () => app(LiveFeed::class)->alive());

        // Authed key check (live mode only): an expired / IP-locked / revoked CDP key must show red,
        // not silently fail on the first order. Cached 5 min so the dashboard doesn't hammer the API.
        // docs/COINBASE_DOCS.md → rest-api/accounts/list-accounts
        if ($this->settings->mode() === 'live') {
            $checks['coinbase_key'] = $this->try(fn () => Cache::remember('desk:keycheck', 300, function () {
                $account = $this->exchange->account();
                if ($account === null) {
                    return false;
                }

                return ($account->keyPermissions()['can_trade'] ?? false) === true;
            }));
        }

        $wm = (string) $this->settings->get('worldmonitor.base_url', '');
        if ($wm !== '') {
            $checks['worldmonitor'] = $this->try(fn () => self::safeWorldMonitorUrl($wm) && Http::timeout(5)->withoutRedirecting()->get(rtrim($wm, '/').'/api/market/v1/get-fear-greed-index')->ok());
        }

        $green = count(array_filter($checks));
        $required = ['database', 'redis', 'coinbase_public'];
        if (isset($checks['coinbase_key'])) {
            $required[] = 'coinbase_key';   // live: no valid trading key → not READY
        }
        $ready = $green >= 3 && count(array_filter(array_intersect_key($checks, array_flip($required)))) === count($required);

        return [
            'ready' => $ready,
            'degraded' => ! $ready || ! ($checks['candles_fresh'] ?? false) || (isset($checks['worldmonitor']) && ! $checks['worldmonitor']),
            'checks' => $checks,
            'halted' => $this->halted(),
            'running' => $this->running(),
            'heartbeats' => $this->heartbeats(),
            'mode' => $this->settings->mode(),
            'strategy' => $this->settings->strategyKey(),
        ];
    }

    /**
     * WorldMonitor is a local instance, so loopback/LAN stay allowed; only non-http schemes and
     * link-local/cloud-metadata addresses (169.254.0.0/16, fd00:ec2::/32) are refused.
     */
    public static function safeWorldMonitorUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return false;
        }
        $host = trim($parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (self::isMetadataAddress($ip)) {
                return false;
            }
        }

        return true;
    }

    /** @return string[] every A and AAAA address (plus the system resolver's view, for hosts-file names) */
    private static function resolve(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $rec) {
            $ips[] = $rec['ip'] ?? $rec['ipv6'] ?? null;
        }

        return array_values(array_unique(array_filter($ips)));
    }

    private static function isMetadataAddress(string $ip): bool
    {
        $bin = inet_pton($ip);
        if ($bin === false) {
            return true;
        }
        if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10)."\xff\xff")) {
            $bin = substr($bin, 12);   // IPv4-mapped ::ffff:a.b.c.d
        }
        $inCidr = fn (string $net, int $bits) => self::cidrMatch($bin, (string) inet_pton($net), $bits);

        return strlen($bin) === 4
            ? $inCidr('169.254.0.0', 16)
            : $inCidr('fe80::', 10) || $inCidr('fd00:ec2::', 32);
    }

    private static function cidrMatch(string $bin, string $net, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if (substr($bin, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;

        return (ord($bin[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }

    private function try(callable $fn): bool
    {
        try {
            return (bool) $fn();
        } catch (\Throwable) {
            return false;
        }
    }
}
