<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Desk\Chief;
use App\Desk\Desk;
use App\Desk\PaperBook;
use App\Desk\Settings;
use App\Exchange\Contracts\MarketData;
use App\Models\Position;
use Illuminate\Console\Command;

class DeskControl extends Command
{
    protected $signature = 'desk:ctl {action : status|halt|resume|stop|close|paper-reset|set|get} {arg?} {value?} {--reason=manual}';

    protected $description = 'Desk controls: status, halt/resume, stop, close <product|all>, paper-reset, set <key> <value>, get <key>';

    public function handle(Chief $chief, Desk $desk, Settings $settings, MarketData $market): int
    {
        switch ($this->argument('action')) {
            case 'status':
                $h = $chief->health();
                $this->line('mode: '.$h['mode'].'  strategy: '.$h['strategy'].'  ready: '.($h['ready'] ? 'YES' : 'no').'  running: '.($h['running'] ? 'yes' : 'no').'  halted: '.($h['halted']['reason'] ?? 'no'));
                $this->table(['check', 'ok'], collect($h['checks'])->map(fn ($v, $k) => [$k, $v ? '✅' : '❌'])->values()->all());
                $this->table(['agent', 'last heartbeat (s ago)'], collect($h['heartbeats'])->map(fn ($v, $k) => [$k, $v ?? '—'])->values()->all());
                try {
                    $bank = $desk->bank();
                    $this->table(array_keys($bank->toArray()), [array_values($bank->toArray())]);
                } catch (\Throwable $e) {
                    $this->warn('bank: '.$e->getMessage());
                }
                foreach ($desk->openPositions() as $p) {
                    $px = $market->price($p->product_id) ?? $p->last_price;
                    $this->line(sprintf('  %-10s qty %.6f entry %.6f now %.6f pnl %+.2f%% held %dm', $p->product_id, $p->quantity, $p->entry_price, $px, $p->unrealisedPnlPct((float) $px), $p->heldMinutes()));
                }
                break;
            case 'halt':
                $chief->halt((string) $this->option('reason'));
                break;
            case 'resume':
                $chief->resume();
                break;
            case 'stop':
                $chief->setRunning(false);
                break;
            case 'close':
                $target = (string) $this->argument('arg');
                $q = Position::open()->mode($desk->mode());
                if ($target !== 'all') {
                    $q->where('product_id', strtoupper($target));
                }
                foreach ($q->get() as $p) {
                    $px = $market->price($p->product_id) ?? (float) $p->last_price;
                    $desk->close($p, 'manual', (float) $px);
                    $this->info("closed {$p->product_id}");
                }
                break;
            case 'paper-reset':
                PaperBook::reset();
                $this->info('paper book reset; starting cash will be re-deposited on next use');
                break;
            case 'set':
                $key = (string) $this->argument('arg');
                // MariaDB compares keys case- and accent-insensitively, so only the canonical spelling of a
                // storable key may reach the live-mode check below; secrets are never written from here.
                if (! Settings::isCanonicalKey($key) || Settings::isSecret($key) || ! Settings::isStorableKey($key)) {
                    $this->error('invalid or protected setting key');

                    return self::FAILURE;
                }
                $v = $this->argument('value');
                $v = is_numeric($v) ? $v + 0 : (in_array($v, ['true', 'false'], true) ? $v === 'true' : $v);
                if ($this->argument('arg') === 'mode' && strtolower(trim((string) $v)) === 'live') {
                    $password = $this->secret('Master password (required to go live)');
                    if (! is_string($password) || $password === '' || ! $settings->verifyMasterPassword($password)) {
                        $this->error('wrong password, mode not changed');

                        return self::FAILURE;
                    }
                }
                $settings->set((string) $this->argument('arg'), $v);
                $this->info('set '.$this->argument('arg').' = '.json_encode($v));
                break;
            case 'get':
                $this->line(json_encode($settings->get((string) $this->argument('arg'))));
                break;
            default:
                $this->error('unknown action');

                return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
