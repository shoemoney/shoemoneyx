<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Desk\Chief;
use App\Desk\Desk;
use App\Desk\Settings;
use App\Exchange\Contracts\MarketData;
use App\Models\PaperLedger;
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
                // Children first: MariaDB refuses the parent delete when a cascading child row is locked by another session.
                $ids = Position::mode('paper')->pluck('id');
                \Illuminate\Support\Facades\DB::table('risk_checks')->whereIn('position_id', $ids)->delete();
                \Illuminate\Support\Facades\DB::table('fills')->whereIn('position_id', $ids)->update(['position_id' => null]);
                Position::mode('paper')->delete();
                PaperLedger::truncate();
                $this->info('paper book reset; starting cash will be re-deposited on next use');
                break;
            case 'set':
                $v = $this->argument('value');
                $v = is_numeric($v) ? $v + 0 : (in_array($v, ['true', 'false'], true) ? $v === 'true' : $v);
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
