<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exchange\ConformanceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/exchanges (resources/js/pages/Exchanges.vue's data source). Badge rule (docs/EXCHANGES.md,
 * CONTRIBUTING.md#badge-rules): conformant only when both a recorded fixture directory and a
 * conformance test class exist in the repo. Coinbase (native) has both; every ccxt id (spot-only,
 * no adapter-specific fixtures/tests today) has neither.
 */
class ExchangeDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_coinbase_is_native_and_conformance_passed(): void
    {
        $res = $this->getJson('/api/exchanges')->assertOk()->json('data');
        $coinbase = collect($res)->firstWhere('id', 'coinbase');

        $this->assertNotNull($coinbase);
        $this->assertSame('native', $coinbase['kind']);
        $this->assertSame('passed', $coinbase['conformance']);
        $this->assertSame('Coinbase', $coinbase['name']);
        $this->assertTrue($coinbase['capabilities']['spot']);
        $this->assertTrue($coinbase['active']); // config('exchanges.active') defaults to coinbase
    }

    public function test_a_ccxt_id_with_no_fixtures_is_unverified(): void
    {
        // Test env disables ccxt by default (CCXT_EXCHANGES="") so a typo can't silently reach a
        // venue nobody chose — enable one explicitly to exercise the registry's ccxt branch.
        config(['exchanges.ccxt.enabled' => 'kraken']);

        $res = $this->getJson('/api/exchanges')->assertOk()->json('data');
        $kraken = collect($res)->firstWhere('id', 'kraken');

        $this->assertNotNull($kraken);
        $this->assertSame('ccxt', $kraken['kind']);
        $this->assertSame('unverified', $kraken['conformance']);
    }

    public function test_every_enabled_ccxt_id_from_config_is_listed(): void
    {
        config(['exchanges.ccxt.enabled' => 'kraken,okx']);

        $ids = collect($this->getJson('/api/exchanges')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertContains('kraken', $ids);
        $this->assertContains('okx', $ids);
    }

    public function test_conformance_status_requires_both_fixtures_and_a_test_class(): void
    {
        $this->assertSame('passed', ConformanceStatus::for('coinbase'));
        $this->assertSame('unverified', ConformanceStatus::for('nonexistent-exchange-id'));
    }
}
