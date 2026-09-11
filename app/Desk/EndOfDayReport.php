<?php

declare(strict_types=1);

namespace App\Desk;

use App\Models\BankSnapshot;
use App\Models\Candidate;
use App\Models\DeskRun;
use App\Models\Fill;
use App\Models\Position;
use Carbon\CarbonInterface;

/**
 * "Assemble it, never invent it." Candidates, rejections with the check that
 * fired, sizes with the Kelly clamp, fills with slippage, closes with the rule
 * that fired, bank open/close, P&L as a percent of the money that was working.
 */
class EndOfDayReport
{
    public function __construct(private Desk $desk, private Reporter $reporter) {}

    public function build(?CarbonInterface $day = null, ?string $mode = null): array
    {
        $day ??= now();
        $mode ??= $this->desk->mode();
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();

        $runs = DeskRun::where('mode', $mode)->whereBetween('started_at', [$from, $to])->get();
        $candidates = Candidate::whereIn('desk_run_id', $runs->pluck('id'))->get();
        $rejections = $candidates->where('verdict', 'REJECT')->groupBy('failed_check')->map->count()->sortDesc();
        $sized = $candidates->whereNotNull('size_usd')->where('size_usd', '>', 0);
        $fills = Fill::where('mode', $mode)->whereBetween('created_at', [$from, $to])->get();
        $closes = Position::mode($mode)->where('status', 'closed')->whereBetween('closed_at', [$from, $to])->get();
        $opens = Position::open()->mode($mode)->get();

        $bankOpen = BankSnapshot::where('mode', $mode)->where('taken_at', '>=', $from)->orderBy('taken_at')->first();
        $bankClose = BankSnapshot::where('mode', $mode)->where('taken_at', '<=', $to)->orderByDesc('taken_at')->first();

        $working = (float) $fills->where('side', 'BUY')->where('status', 'filled')->sum('filled_usd');
        $realised = (float) $closes->sum('pnl_usd');

        return [
            'date' => $from->toDateString(),
            'mode' => $mode,
            'cycles' => $runs->count(),
            'products_scanned' => (int) $runs->max('products_scanned'),
            'candidates' => $candidates->count(),
            'rejections' => $rejections->all(),
            'rejections_total' => $candidates->where('verdict', 'REJECT')->count(),
            'passed' => $candidates->whereIn('verdict', ['PASS', 'PASS_PARTIAL'])->count(),
            'sizes' => $sized->map(fn ($c) => [
                'product' => $c->product_id, 'usd' => $c->size_usd, 'pct_bank' => $c->pct_of_bank, 'ceiling' => (bool) $c->ceiling_applied,
            ])->values()->all(),
            'fills' => $fills->where('status', 'filled')->map(fn ($f) => [
                'product' => $f->product_id, 'side' => $f->side, 'kind' => $f->kind, 'usd' => $f->filled_usd,
                'slippage_bps' => $f->slippage_bps, 'fee_pct' => $f->fee_pct, 'partial' => $f->partial,
            ])->values()->all(),
            'closes' => $closes->map(fn ($p) => [
                'product' => $p->product_id, 'rule' => $p->close_rule, 'pnl_usd' => $p->pnl_usd, 'pnl_pct' => $p->pnl_pct, 'held_minutes' => $p->heldMinutes(),
            ])->values()->all(),
            'wins' => $closes->where('pnl_usd', '>', 0)->count(),
            'losses' => $closes->where('pnl_usd', '<=', 0)->count(),
            'realised_pnl_usd' => round($realised, 2),
            'working_usd' => round($working, 2),
            'pnl_pct_of_working' => $working > 0 ? round($realised / $working * 100, 2) : null,
            'bank_open' => $bankOpen?->equity,
            'bank_close' => $bankClose?->equity,
            'open_positions' => $opens->count(),
        ];
    }

    public function text(array $r): string
    {
        $lines = [];
        $lines[] = sprintf('<b>ShoeMoneyX %s report — %s</b>', strtoupper($r['mode']), $r['date']);
        $lines[] = sprintf('cycles %d · scanned %d · candidates %d · passed %d · rejected %d', $r['cycles'], $r['products_scanned'], $r['candidates'], $r['passed'], $r['rejections_total']);
        if ($r['rejections']) {
            $lines[] = 'rejections: '.collect($r['rejections'])->map(fn ($n, $k) => "{$k} {$n}")->implode(', ');
        }
        $lines[] = sprintf('fills %d · closes %d (%dW/%dL)', count($r['fills']), count($r['closes']), $r['wins'], $r['losses']);
        foreach ($r['closes'] as $c) {
            $lines[] = sprintf('  %s [%s] $%.2f (%.1f%%) %dm', $c['product'], $c['rule'], $c['pnl_usd'], $c['pnl_pct'], $c['held_minutes']);
        }
        $lines[] = sprintf('bank open $%s → close $%s', $r['bank_open'] !== null ? number_format($r['bank_open'], 2) : '—', $r['bank_close'] !== null ? number_format($r['bank_close'], 2) : '—');
        $lines[] = sprintf('realised $%.2f = %s of the $%.2f that was working', $r['realised_pnl_usd'], $r['pnl_pct_of_working'] !== null ? $r['pnl_pct_of_working'].'%' : 'n/a', $r['working_usd']);
        $lines[] = sprintf('open positions: %d', $r['open_positions']);

        return implode("\n", $lines);
    }

    public function send(?CarbonInterface $day = null): array
    {
        $r = $this->build($day);
        $text = $this->text($r);
        $this->reporter->info('CHIEF', 'end of day report', $r);
        $this->reporter->telegram($text);

        return $r;
    }
}
