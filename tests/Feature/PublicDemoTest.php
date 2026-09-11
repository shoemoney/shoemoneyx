<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Chief;
use App\Desk\Desk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PublicDemoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['site.demo' => true, 'session.driver' => 'array', 'cache.default' => 'array']);
        $this->withoutVite();
        Http::preventStrayRequests();
        Queue::fake();
        DB::listen(fn () => throw new \RuntimeException('Public demo attempted database access'));
        foreach ([Chief::class, Desk::class] as $class) {
            $this->app->bind($class, fn () => throw new \RuntimeException('Demo resolved the trading desk'));
        }
    }

    public function test_all_working_pages_and_read_apis_work_without_a_database_or_exchange(): void
    {
        foreach (['/', '/dashboard', '/chart', '/desk', '/positions', '/backtests', '/optimizer', '/arena', '/settings'] as $path) {
            $this->get($path)->assertOk()->assertSee('INTERACTIVE DEMO')->assertSee('data-demo="true"', false);
        }
        foreach (['status', 'report', 'products', 'positions', 'positions/1', 'runs', 'runs/latest', 'runs/42', 'events', 'candidates', 'fills', 'bank/history', 'settings', 'quote?product=BTC-USD', 'backtests', 'backtests/1', 'optimizer/champions', 'optimizer/rounds', 'optimizer/candidates?coin=BTC-USD', 'arena', 'farm', 'udf/config', 'udf/time', 'udf/search?query=BTC', 'udf/symbols?symbol=BTC-USD', 'udf/symbols?symbol=DEMO:BNB-USD', 'udf/marks', 'smx?product=BTC-USD&tf=15s'] as $path) {
            $this->getJson('/api/'.$path)->assertOk()->assertHeader('X-ShoeMoney-Data', 'simulated');
        }
        Queue::assertNothingPushed();
    }

    public function test_mutations_are_blocked_before_bindings_or_execution_even_for_unknown_routes(): void
    {
        foreach ([['POST', '/api/desk/cycle'], ['POST', '/api/positions/1/close'], ['POST', '/api/backtests'], ['PUT', '/api/settings'], ['DELETE', '/api/settings/mode'], ['POST', '/broadcasting/auth'], ['POST', '/api/future-operation']] as [$method, $path]) {
            $this->json($method, $path, ['mode' => 'live'])->assertForbidden()->assertJsonPath('message', 'This public demo is read-only. No trades, jobs or settings are changed.');
        }
        $this->getJson('/api/not-a-demo-endpoint')->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_candles_are_bounded_valid_and_repeatable_at_a_fixed_time(): void
    {
        foreach (['15S', '1', '5'] as $tf) {
            $url = '/api/udf/history?symbol=BTC-USD&resolution='.$tf.'&from=1&to='.(time() - 600);
            $bars = $this->getJson($url)->assertOk()->json();
            $this->assertNotEmpty($bars['t']);
            $this->assertLessThanOrEqual(600, count($bars['t']));
            $this->assertSame($bars, $this->getJson($url)->json());
            foreach ($bars['t'] as $i => $t) {
                $this->assertGreaterThan(0, $bars['l'][$i]);
                $this->assertGreaterThanOrEqual(max($bars['o'][$i], $bars['c'][$i]), $bars['h'][$i]);
                $this->assertLessThanOrEqual(min($bars['o'][$i], $bars['c'][$i]), $bars['l'][$i]);
                if ($i > 0) {
                    $this->assertGreaterThan($bars['t'][$i - 1], $t);
                }
            }
        }
        $this->getJson('/api/quote?product=INVALID-USD')->assertNotFound();
    }

    public function test_account_totals_reconcile_and_demo_switch_defaults_off(): void
    {
        $bank = $this->getJson('/api/status')->assertJsonPath('demo', true)->json('bank');
        $this->assertEqualsWithDelta($bank['cash'] + $bank['positions_value'], $bank['equity'], .001);
        config(['site.demo' => false]);
        $this->get('/dashboard')->assertOk()->assertDontSee('INTERACTIVE DEMO')->assertSee('data-demo="false"', false);
    }
}
