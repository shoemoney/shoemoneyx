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
            $checks['worldmonitor'] = $this->try(fn () => Http::timeout(5)->get(rtrim($wm, '/').'/api/market/v1/get-fear-greed-index')->ok());
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

    private function try(callable $fn): bool
    {
        try {
            return (bool) $fn();
        } catch (\Throwable) {
            return false;
        }
    }
}
