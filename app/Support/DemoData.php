<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Indicators\Smx;
use Illuminate\Http\Request;

/** Deterministic, on-demand showcase data. No database, network, queues or persisted state. */
class DemoData
{
    private const MARKETS = ['BTC' => 97284.16, 'ETH' => 3241.87, 'SOL' => 184.32, 'XRP' => 2.41, 'LINK' => 23.68, 'DOGE' => .3184, 'ADA' => .8942, 'AVAX' => 38.72, 'SUI' => 4.23, 'LTC' => 112.54, 'ZEC' => 58.21, 'BNB' => 684.22, 'ETC' => 28.64, 'BCH' => 482.16, 'XMR' => 218.42, 'XLM' => .4321, 'DASH' => 42.86, 'EOS' => .8624];

    private function iso(int $ago = 0): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', time() - $ago);
    }

    private function symbol(string $symbol): string
    {
        $symbol = preg_replace('/^(?:COINBASE|DEMO):/', '', strtoupper($symbol));
        abort_unless(isset(self::MARKETS[explode('-', $symbol)[0]]) && str_ends_with($symbol, '-USD'), 404, 'Unknown demo market');

        return $symbol;
    }

    private function price(string $symbol, int $time): float
    {
        $coin = explode('-', $this->symbol($symbol))[0];
        $phase = array_search($coin, array_keys(self::MARKETS), true);

        return self::MARKETS[$coin] * (1 + .014 * sin($time / 8100 + $phase) + .004 * sin($time / 730 + $phase) + .0005 * sin($time / 17 + $phase));
    }

    private function products(): array
    {
        $rows = [];
        foreach (self::MARKETS as $coin => $base) {
            $symbol = $coin.'-USD';
            $rows[] = ['product_id' => $symbol, 'base_currency' => $coin, 'quote_currency' => 'USD', 'price' => $this->price($symbol, time()), 'price_change_24h_pct' => round(sin(count($rows) + .4) * 6, 2), 'volume_24h_usd' => round(1364000000 / (1 + count($rows) * .8)), 'status' => 'online', 'listed_at' => '2020-01-01T00:00:00Z'];
        }

        return $rows;
    }

    private function positions(bool $closed = false): array
    {
        $rows = [];
        foreach (array_slice($this->products(), 0, 8) as $i => $p) {
            $side = $i % 3 === 0 ? 'short' : 'long';
            $entry = self::MARKETS[$p['base_currency']] * (1 + ($side === 'short' ? .028 : -.024));
            $qty = (3500 + $i * 850) / $entry;
            $pnl = ($p['price'] - $entry) * $qty * ($side === 'short' ? -1 : 1);
            $rows[] = ['id' => $i + ($closed ? 101 : 1), 'product_id' => $p['product_id'], 'mode' => 'demo', 'strategy' => 'mr', 'side' => $side, 'status' => $closed ? 'closed' : 'open', 'quantity' => $qty, 'entry_price' => $entry, 'entry_usd' => $qty * $entry, 'mark_price' => $p['price'], 'last_price' => $p['price'], 'peak_price' => $p['price'] * 1.005, 'exit_price' => $closed ? $p['price'] : null, 'opened_at' => $this->iso(7200 + $i * 500), 'closed_at' => $closed ? $this->iso(1200 + $i * 120) : null, 'held_minutes' => 120 + $i * 8, 'unrealised_pnl' => $closed ? null : $pnl, 'unrealised_pnl_pct' => $closed ? null : $pnl / ($qty * $entry) * 100, 'pnl_usd' => $closed ? $pnl : null, 'pnl_pct' => $closed ? $pnl / ($qty * $entry) * 100 : null, 'realised_usd' => 0, 'fees_usd' => 3.5, 'adds' => $i % 3, 'adds_count' => $i % 3, 'meta' => ['demo' => true, 'leverage' => 3, 'margin_usd' => $qty * $entry / 3]];
        }

        return $rows;
    }

    private function fills(): array
    {
        return array_map(fn ($p) => ['id' => $p['id'], 'position_id' => $p['id'], 'product_id' => $p['product_id'], 'mode' => 'demo', 'side' => $p['side'] === 'short' ? 'SELL' : 'BUY', 'kind' => 'entry', 'status' => 'filled', 'filled_qty' => $p['quantity'], 'requested_usd' => $p['entry_usd'], 'filled_usd' => $p['entry_usd'], 'decision_price' => $p['entry_price'], 'fill_price' => $p['entry_price'], 'fee_usd' => 3.5, 'fee_pct' => .001, 'slippage_bps' => 1.2, 'created_at' => $p['opened_at'], 'why' => 'Simulated demonstration fill'], $this->positions());
    }

    private function candidates(): array
    {
        return array_map(fn ($p, $i) => ['id' => $i + 1, 'desk_run_id' => 42, 'product_id' => $p['product_id'], 'score' => 94 - $i * 3, 'rank' => $i + 1, 'verdict' => $i % 4 ? 'PASS' : 'REJECT', 'why' => $i % 4 ? 'Demo signal passed the simulated checks' : 'Demo spread exceeds the example limit', 'rank_reason' => 'Simulated momentum + liquidity', 'failed_check' => $i % 4 ? null : 'spread', 'metrics' => ['volume_surge_h1' => 1.4 + $i * .2, 'buys_h1' => 620 + $i * 40, 'sells_h1' => 380, 'price_change_h1_pct' => round(sin($i + 1) * 2, 2), 'spread_bps' => $i % 4 ? 3.2 : 18], 'size_usd' => $i % 4 ? 3500 + $i * 850 : 0, 'pct_of_bank' => $i % 4 ? round((3500 + $i * 850) / 135000 * 100, 1) : 0, 'size_why' => $i % 4 ? 'Simulated allocation' : 'Demo spread rejection', 'created_at' => $this->iso(12 + $i), 'meta' => ['side' => $i % 3 ? 'long' : 'short']], array_slice($this->products(), 0, 8), range(0, 7));
    }

    private function run(int $id = 42): array
    {
        return ['id' => $id, 'mode' => 'demo', 'strategy' => 'mr', 'status' => 'done', 'degraded' => false, 'products_scanned' => 18, 'passed' => 6, 'rejected' => 2, 'filled' => 3, 'started_at' => $this->iso(15 + (42 - $id) * 60), 'finished_at' => $this->iso(10 + (42 - $id) * 60), 'candidates' => $this->candidates(), 'fills' => array_slice($this->fills(), 0, 3)];
    }

    private function events(Request $request): array
    {
        $rows = [];
        $agents = ['SCAN', 'VET', 'SIZE', 'FILLS', 'RISK', 'AI'];
        $last = intdiv(time(), 2);
        for ($i = 0; $i < 40; $i++) {
            $id = $last - $i;
            if ($id <= $request->integer('after', 0)) {
                continue;
            }
            $agent = $agents[$id % 6];
            if ($request->filled('agent') && $request->string('agent')->upper()->toString() !== $agent) {
                continue;
            }
            $coin = array_keys(self::MARKETS)[$id % 18].'-USD';
            $rows[] = ['id' => $id, 'agent' => $agent, 'level' => 'info', 'message' => "Demo {$coin} · ".['signal identified', 'checks passed', 'allocation evaluated', 'simulated fill', 'exposure reviewed', 'candidate scored'][$id % 6], 'created_at' => gmdate('Y-m-d\TH:i:s\Z', $id * 2), 'payload' => ['product_id' => $coin], 'scope' => 'demo'];
        }

        return $rows;
    }

    private function bank(): array
    {
        $positions = $this->positions();
        $value = array_sum(array_column($positions, 'entry_usd')) + array_sum(array_column($positions, 'unrealised_pnl'));

        return ['cash' => 82500, 'equity' => 82500 + $value, 'positions_value' => $value, 'free_cash' => 70500, 'collateral' => 12000, 'locked' => 12000, 'exposure' => $value, 'buying_power' => 211500, 'taken_at' => $this->iso()];
    }

    private function parameters(): array
    {
        return ['timeframe' => '1m', 'lookback' => 60, 'entry_z' => 2.0, 'exit_z' => 0.3, 'stop_z' => 3.5, 'max_hold_bars' => 240, 'qty_pct' => 10, 'max_position_pct' => 60, 'leverage' => 1, 'allow_shorts' => true, 'trend_ma' => 0, 'tsl_pct' => .8, 'cooldown_bars' => 5];
    }

    private function point(int $i): array
    {
        return ['cand' => $i, 'batch' => 'demo-'.intdiv(time(), 3600), 'at' => $this->iso($i * 3), 'tf' => ['15s', '1m', '5m'][$i % 3], 'x1' => 3 + $i % 12, 'x2' => 12 + $i % 24, 'base' => 30 + ($i % 10) * 15, 'tp' => .5 + ($i % 8) * .25, 'rungs' => 2 + $i % 5, 'lev' => 1 + $i % 3, 'qty' => 1, 'ma' => 'EMA', 'stop' => 2, 'gate' => 200, 'tsl' => .8, 'ttp' => 1.2, 'ttpg' => .3, 'train' => round(6 + sin($i * 1.3) * 7 + cos($i * .07) * 4, 2), 'test' => round(3 + sin($i * 1.3) * 5 + sin($i * .7) * 3, 2), 'trades' => 30 + $i % 170, 'pf' => round(1.2 + ($i % 20) / 20, 2), 'dd' => round(1 + ($i % 30) / 10, 2)];
    }

    private function rounds(): array
    {
        $rows = [];
        for ($i = 0; $i < 72; $i++) {
            $point = $this->point($i);
            $rows[] = ['id' => 1000 - $i, 'product_id' => array_keys(self::MARKETS)[$i % 18].'-USD', 'side' => $i % 2 ? 'short' : 'long', 'strategy' => 'mr', 'cash' => 10000, 'tag' => 'demo', 'candidates' => 32, 'promoted' => $i % 7 === 0, 'champion_train' => 8.4, 'champion_test' => 4.2, 'best_train' => $point['train'], 'best_test' => $point['test'], 'best_calmar' => round(1.5 + ($i % 9) / 10, 2), 'best_dsr' => .92, 'best_plateau' => .76, 'note' => 'Synthetic showcase round', 'created_at' => $this->iso($i * 45)];
        }

        return $rows;
    }

    private function candles(string $symbol, int $duration, int $from, int $to): array
    {
        $to = min(time(), $to);
        $from = max($from, $to - 600 * $duration);
        $start = intdiv($from, $duration) * $duration;
        $rows = [];
        for ($t = $start; $t <= $to; $t += $duration) {
            $o = $this->price($symbol, $t);
            $c = $this->price($symbol, min($t + $duration - 1, time()));
            $rows[] = ['start' => $t, 'open' => $o, 'high' => max($o, $c) * 1.0007, 'low' => min($o, $c) * .9993, 'close' => $c, 'volume' => (4000 + abs(sin($t)) * 12000) / $o];
        }

        return array_slice($rows, -600);
    }

    private function backtest(int $id): array
    {
        abort_unless($id >= 1 && $id <= 8, 404, 'Unknown demo experiment');
        $cash = 10000;
        $curve = [];
        for ($i = 0; $i <= 180; $i++) {
            $curve[] = [time() - (180 - $i) * 14400, $cash * (1 + $i * .0012 + sin($i / 8) * .008)];
        }
        $equity = $curve[180][1];
        $trades = [];
        foreach ($this->positions(true) as $p) {
            $trades[] = ['product' => $p['product_id'], 'opened_at' => $p['opened_at'], 'closed_at' => $p['closed_at'], 'held_hours' => 2.3, 'usd' => $p['entry_usd'], 'entry' => $p['entry_price'], 'exit' => $p['exit_price'], 'pnl_usd' => $p['pnl_usd'], 'pnl_pct' => $p['pnl_pct'], 'rule' => 'demo_target', 'why' => 'Synthetic example — not an actual backtest'];
        }

        return ['id' => $id, 'strategy' => 'mr', 'status' => 'done', 'products' => array_column(array_slice($this->products(), 0, 8), 'product_id'), 'from' => $this->iso(2592000), 'to' => $this->iso(), 'created_at' => $this->iso($id * 600), 'starting_cash' => $cash, 'ending_equity' => $equity, 'params' => ['demo' => true], 'stats' => ['days' => 30, 'total_return_pct' => ($equity / $cash - 1) * 100, 'trades' => 128, 'wins' => 88, 'losses' => 40, 'win_rate' => 68.75, 'profit_factor' => 1.82, 'max_drawdown_pct' => 3.4, 'avg_win_pct' => 1.2, 'avg_loss_pct' => -.6, 'avg_hold_hours' => 2.3, 'candidates' => 780, 'sized_zero' => 42, 'exit_rules' => ['demo_target' => 88, 'demo_stop' => 40], 'rejections' => ['demo_spread' => 42]], 'equity_curve' => $curve, 'trades' => $trades];
    }

    public function get(Request $request): mixed
    {
        $path = $request->path();
        $symbol = $this->symbol((string) $request->input('product', $request->input('symbol', $request->input('coin', 'BTC-USD'))));
        if ($path === 'api/status') {
            return ['demo' => true, 'health' => ['mode' => 'demo', 'strategy' => 'mr', 'ready' => true, 'running' => true, 'halted' => null, 'heartbeats' => ['SCAN' => 2, 'VET' => 3, 'SIZE' => 1, 'FILLS' => 4, 'RISK' => 2], 'checks' => ['demo_data' => true]], 'bank' => $this->bank(), 'bank_error' => null, 'open_positions' => 8, 'closed_today' => 12, 'realised_today' => 2480.62, 'realised_total' => 12480.62, 'last_run' => $this->run(), 'live_confirm' => false, 'server_time' => $this->iso()];
        }
        if ($path === 'api/report') {
            return ['demo' => true, 'report' => ['cycles' => 42, 'candidates' => 336, 'passed' => 252, 'rejections_total' => 84, 'wins' => 9, 'losses' => 3, 'realised_pnl_usd' => 2480.62, 'working_usd' => 51800, 'pnl_pct_of_working' => 4.79]];
        }
        if ($path === 'api/products') {
            return $this->products();
        }
        if ($path === 'api/quote') {
            return ['product_id' => $symbol, 'source' => 'demo', 'price' => $this->price($symbol, time()), 'bid' => $this->price($symbol, time()) * .99997, 'ask' => $this->price($symbol, time()) * 1.00003, 'vol24' => 180000000, 'chg24' => 2.84, 'ts' => time()];
        }
        if ($path === 'api/positions') {
            return $this->positions($request->input('status') === 'closed');
        }
        if (preg_match('~^api/positions/(\d+)$~', $path, $m)) {
            $row = collect([...$this->positions(), ...$this->positions(true)])->firstWhere('id', (int) $m[1]);
            abort_unless($row, 404, 'Unknown demo position');

            return $row + ['fills' => array_values(array_filter($this->fills(), fn ($f) => $f['position_id'] === $row['id'])), 'risk_checks' => [['id' => 1, 'created_at' => $this->iso(5), 'action' => 'hold', 'rule' => 'demo_exposure', 'why' => 'Synthetic risk review', 'price' => $row['mark_price'], 'pnl_pct' => $row['unrealised_pnl_pct']]]];
        }
        if ($path === 'api/events') {
            return $this->events($request);
        }
        if ($path === 'api/runs/latest') {
            return $this->run();
        }
        if (preg_match('~^api/runs/(\d+)$~', $path, $m)) {
            abort_unless((int) $m[1] >= 1 && (int) $m[1] <= 42, 404);

            return $this->run((int) $m[1]);
        }
        if (in_array($path, ['api/runs', 'api/candidates', 'api/fills'])) {
            $rows = $path === 'api/runs' ? array_map(fn ($id) => $this->run($id), range(42, 31)) : ($path === 'api/fills' ? $this->fills() : $this->candidates());
            if ($request->filled('product')) {
                $rows = array_values(array_filter($rows, fn ($r) => ($r['product_id'] ?? '') === $symbol));
            }

            return ['data' => $rows, 'current_page' => 1, 'last_page' => 1, 'total' => count($rows), 'next_page_url' => null, 'prev_page_url' => null];
        }
        if ($path === 'api/bank/history') {
            $hours = min(168, max(1, $request->integer('hours', 72)));
            $rows = [];
            $current = $this->bank()['equity'];
            for ($i = 0; $i <= 240; $i++) {
                $equity = $current - (240 - $i) * 35 + (sin($i / 9) - sin(240 / 9)) * 500;
                $rows[] = ['taken_at' => $this->iso((int) (($hours * 3600) * (1 - $i / 240))), 'equity' => $equity, 'cash' => 82500, 'positions_value' => $equity - 82500, 'free_cash' => 70500];
            }

            return $rows;
        }
        if ($path === 'api/settings') {
            $params = ['risk.max_spread_bps' => 12, 'risk.max_exposure_pct' => 30, 'risk.max_open_positions' => 8, 'paper.starting_cash' => 100000, 'chief.heartbeat_seconds' => 60];
            foreach ($this->parameters() as $key => $value) {
                $params['mr.'.$key] = $value;
            }

            return ['mode' => 'demo', 'strategy' => 'mr', 'strategies' => [['key' => 'mr', 'name' => 'Mean Reversion · demo']], 'live_confirm' => false, 'params' => $params, 'overrides' => []];
        }
        if ($path === 'api/backtests') {
            return ['data' => array_map(fn ($id) => array_diff_key($this->backtest($id), array_flip(['equity_curve', 'trades'])), range(8, 1)), 'next_cursor' => null, 'has_more' => false];
        }
        if (preg_match('~^api/backtests/(\d+)$~', $path, $m)) {
            return $this->backtest((int) $m[1]);
        }
        if ($path === 'api/optimizer/champions') {
            return ['coins' => array_map(fn ($coin) => ['coin' => $coin.'-USD', 'long' => $this->parameters(), 'short' => $this->parameters(), 'shorts_enabled' => true, 'rounds' => ['long' => ['at' => $this->iso(20), 'train' => 8.4, 'test' => 4.2, 'note' => 'Simulated scores'], 'short' => ['at' => $this->iso(30), 'train' => 7.2, 'test' => 3.6, 'note' => 'Simulated scores']]], array_keys(self::MARKETS)), 'keys' => array_keys($this->parameters()), 'server_time' => $this->iso()];
        }
        if ($path === 'api/optimizer/rounds') {
            return array_values(array_filter($this->rounds(), fn ($r) => (! $request->filled('coin') || $r['product_id'] === $symbol) && (! $request->filled('side') || $r['side'] === $request->input('side')) && (! $request->boolean('promoted') || $r['promoted'])));
        }
        if ($path === 'api/optimizer/candidates') {
            return ['coin' => $symbol, 'side' => $request->input('side', 'long'), 'scanned' => 2400, 'points' => array_map(fn ($i) => $this->point($i), range(1, 1200))];
        }
        if ($path === 'api/arena') {
            return ['coin' => $symbol, 'side' => $request->input('side', 'long'), 'champion' => ['set' => $this->parameters(), 'train' => 8.4, 'test' => 4.2, 'promoted_at' => $this->iso(3600)], 'recent' => array_map(fn ($i) => $this->point($i) + ['params' => $this->point($i)], range(1, 30)), 'today' => ['challengers' => 8400 + time() % 300, 'promotions' => 24], 'server_time' => $this->iso()];
        }
        if ($path === 'api/farm') {
            return ['at' => $this->iso(), 'hosts' => array_map(fn ($i) => ['label' => 'Demo compute '.$i, 'ip' => '192.0.2.'.$i, 'role' => 'demo', 'connections' => 16, 'workers_est' => 8, 'expected' => 8], range(1, 4)), 'queues' => ['backtests' => ['queued' => 48, 'reserved' => 32, 'delayed' => 0, 'oldest_reserved_s' => 2]], 'throughput' => ['done_1m' => 420, 'done_5m' => 2100, 'computed_5m' => 1800, 'reused_5m' => 300, 'running' => 32, 'failed_1h' => 0, 'under_one_contract_5m' => 0], 'optimizers' => [['name' => 'Demo research', 'side' => 'long', 'cash' => 10000, 'last_round_at' => $this->iso(3), 'rounds_1h' => 280, 'promoted_1h' => 24]], 'watch' => ['at' => $this->iso(), 'lines' => ['Simulated infrastructure · no compute jobs are running']]];
        }
        if ($path === 'api/udf/config') {
            return ['supported_resolutions' => ['15S', '1', '5'], 'supports_group_request' => false, 'supports_marks' => true, 'supports_search' => true, 'supports_time' => true];
        }
        if ($path === 'api/udf/time') {
            return time();
        }
        if ($path === 'api/udf/search') {
            return array_map(fn ($p) => ['symbol' => $p['product_id'], 'full_name' => $p['product_id'], 'description' => $p['base_currency'].' / USD · simulated', 'exchange' => 'DEMO', 'ticker' => $p['product_id'], 'type' => 'crypto'], array_values(array_filter($this->products(), fn ($p) => str_contains($p['product_id'], strtoupper((string) $request->input('query', ''))))));
        }
        if ($path === 'api/udf/symbols') {
            return ['name' => $symbol, 'ticker' => $symbol, 'description' => str_replace('-', ' / ', $symbol).' · demo', 'type' => 'crypto', 'session' => '24x7', 'timezone' => 'Etc/UTC', 'exchange' => 'DEMO', 'listed_exchange' => 'DEMO', 'minmov' => 1, 'pricescale' => $this->price($symbol, time()) < 1 ? 1000000 : 100, 'has_intraday' => true, 'has_seconds' => true, 'has_daily' => false, 'seconds_multipliers' => ['15'], 'intraday_multipliers' => ['1', '5'], 'supported_resolutions' => ['15S', '1', '5'], 'volume_precision' => 4, 'data_status' => 'streaming'];
        }
        if ($path === 'api/udf/history' || $path === 'api/smx') {
            $tf = (string) $request->input($path === 'api/smx' ? 'tf' : 'resolution', '1');
            $dur = ['15S' => 15, '15s' => 15, '1' => 60, '1m' => 60, '5' => 300, '5m' => 300][$tf] ?? 60;
            $to = min(time(), $request->integer('to', time()));
            $bars = $this->candles($symbol, $dur, $request->integer('from', $to - 400 * $dur), $to);
            if ($path === 'api/udf/history') {
                return ['s' => $bars ? 'ok' : 'no_data', 't' => array_column($bars, 'start'), 'o' => array_column($bars, 'open'), 'h' => array_column($bars, 'high'), 'l' => array_column($bars, 'low'), 'c' => array_column($bars, 'close'), 'v' => array_column($bars, 'volume')];
            }
            $c = Smx::compute($bars, Smx::DEFAULTS);

            return ['product' => $symbol, 'tf' => $tf, 't' => array_column($bars, 'start'), 'close' => array_column($bars, 'close'), 'latest' => Smx::latest($c), 'levels' => ['ob' => 53, 'ob2' => 60, 'os' => -53, 'os2' => -60, 'os3' => -75]] + $c;
        }
        if ($path === 'api/udf/marks') {
            return [];
        }
        // Unknown endpoints never fall through into private controllers in demo mode.
        abort(404, 'This endpoint is not part of the public demo.');
    }
}
