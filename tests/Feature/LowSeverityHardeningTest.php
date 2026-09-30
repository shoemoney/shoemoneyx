<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Desk\Chief;
use App\Desk\Reporter;
use App\Desk\Settings;
use App\Desk\Strategies\Formula;
use App\Desk\Strategies\FormulaParseError;
use App\Models\CoinbaseApiLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LowSeverityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_failure_log_never_contains_the_bot_token(): void
    {
        config(['desk.telegram.bot_token' => '123456:SECRET-token_x', 'desk.telegram.chat_id' => '1']);
        Http::fake(fn () => throw new ConnectionException('cURL error 6 for https://api.telegram.org/bot123456:SECRET-token_x/sendMessage'));
        Log::spy();

        app(Reporter::class)->telegram('hi');

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $m) => str_contains($m, 'ConnectionException') && ! str_contains($m, 'SECRET-token_x')
        )->once();
    }

    public function test_formula_rejects_overlong_and_too_deep_expressions(): void
    {
        try {
            Formula::parse(str_repeat('1+', 300).'1');
            $this->fail('expected too long');
        } catch (FormulaParseError $e) {
            $this->assertStringContainsString('too long', $e->getMessage());
        }
        try {
            Formula::parse(str_repeat('(', 40).'1'.str_repeat(')', 40));
            $this->fail('expected too deep');
        } catch (FormulaParseError $e) {
            $this->assertStringContainsString('nested', $e->getMessage());
        }
        $this->assertSame(1.0, Formula::evaluate(Formula::parse(str_repeat('(', 20).'1'.str_repeat(')', 20)), []));
        $this->assertSame(3.0, Formula::evaluate(Formula::parse('1+2'), []));
    }

    public function test_oversized_strategy_definition_is_rejected(): void
    {
        $def = ['key' => 'big', 'name' => 'Big', 'version' => 1, 'pad' => str_repeat('a', 70000)];

        $this->postJson('/api/strategy-plugins/validate', ['definition' => $def])->assertUnprocessable();
        $this->postJson('/api/strategy-plugins', ['definition' => $def])->assertUnprocessable();
    }

    public function test_definition_with_invalid_utf8_is_rejected(): void
    {
        $def = ['key' => 'bad', 'name' => "\xB1\x31", 'version' => 1];

        $this->postJson('/api/strategy-plugins/validate', ['definition' => $def])->assertUnprocessable();
    }

    public function test_worldmonitor_health_check_does_not_follow_redirects(): void
    {
        app(Settings::class)->set('worldmonitor.base_url', 'http://127.0.0.1:3000');
        $redirects = null;
        Http::fake(function ($request, array $options) use (&$redirects) {
            $redirects = $options['allow_redirects'] ?? true;

            return Http::response('', 302, ['Location' => 'http://169.254.169.254/']);
        });

        app(Chief::class)->health();

        $this->assertFalse($redirects, 'health probe must not follow redirects');
    }

    public function test_worldmonitor_url_guard_blocks_metadata_but_allows_lan(): void
    {
        $this->assertFalse(Chief::safeWorldMonitorUrl('http://169.254.169.254/latest'));
        $this->assertFalse(Chief::safeWorldMonitorUrl('http://[fd00:ec2::254]/'));
        $this->assertFalse(Chief::safeWorldMonitorUrl('file:///etc/passwd'));
        $this->assertFalse(Chief::safeWorldMonitorUrl('gopher://127.0.0.1'));
        $this->assertFalse(Chief::safeWorldMonitorUrl('http://[fd00:0ec2::254]/'));
        $this->assertFalse(Chief::safeWorldMonitorUrl('http://[FD00:EC2:0:0::1]/'));
        $this->assertFalse(Chief::safeWorldMonitorUrl('http://[fe80::1]/'));
        $this->assertFalse(Chief::safeWorldMonitorUrl('http://[::ffff:169.254.169.254]/'));
        $this->assertFalse(Chief::safeWorldMonitorUrl('http://no-such-host.invalid/'));
        $this->assertTrue(Chief::safeWorldMonitorUrl('http://[fd12::1]/'));
        $this->assertTrue(Chief::safeWorldMonitorUrl('http://127.0.0.1:3000'));
        $this->assertTrue(Chief::safeWorldMonitorUrl('http://192.168.1.10:3000'));
        $this->assertTrue(Chief::safeWorldMonitorUrl('https://10.0.0.5'));
    }

    public function test_coinbase_api_log_hides_bodies_and_prunes_old_rows(): void
    {
        $old = CoinbaseApiLog::create(['method' => 'POST', 'endpoint' => '/x', 'request_body' => ['a' => 1], 'response_body' => ['b' => 2]]);
        $old->forceFill(['created_at' => now()->subDays(8)])->save();
        $fresh = CoinbaseApiLog::create(['method' => 'GET', 'endpoint' => '/y']);

        $this->assertArrayNotHasKey('request_body', $fresh->toArray());
        $this->assertArrayNotHasKey('response_body', $old->toArray());

        $this->artisan('model:prune', ['--model' => CoinbaseApiLog::class])->assertSuccessful();

        $this->assertNull(CoinbaseApiLog::find($old->id));
        $this->assertNotNull(CoinbaseApiLog::find($fresh->id));
    }
}
