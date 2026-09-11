<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Ai\Exceptions\AiGateException;
use App\Ai\Gate;
use App\Models\AiCall;
use App\Models\AiConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiGateTest extends TestCase
{
    use RefreshDatabase;

    private const PROMPT = 'oversold H1 dip with a volume surge and a tight spread';

    private function gate(): Gate
    {
        return app(Gate::class);
    }

    private function connect(string $userKey = 'local'): AiConnection
    {
        return AiConnection::create([
            'user_id' => $userKey, 'provider' => 'openrouter',
            'key' => 'sk-or-v1-test', 'connected_at' => now(),
        ]);
    }

    public function test_the_twenty_first_call_in_a_minute_is_rate_limited(): void
    {
        $gate = $this->gate();
        for ($i = 0; $i < 20; $i++) {
            $gate->ensureAllowed('local');
        }

        try {
            $gate->ensureAllowed('local');
            $this->fail('the 21st call should have been refused');
        } catch (AiGateException $e) {
            $this->assertSame('rate_limited', $e->reason);
        }
    }

    public function test_the_per_minute_budget_is_per_user_key(): void
    {
        config()->set('ai.rate_limit.per_minute', 2);
        $gate = $this->gate();

        $gate->ensureAllowed('alice');
        $gate->ensureAllowed('alice');
        $gate->ensureAllowed('bob');

        $this->expectException(AiGateException::class);
        $gate->ensureAllowed('alice');
    }

    public function test_the_daily_budget_refuses_beyond_its_own_limit(): void
    {
        config()->set('ai.rate_limit.per_minute', 1000);
        config()->set('ai.rate_limit.per_day', 3);
        $gate = $this->gate();

        for ($i = 0; $i < 3; $i++) {
            $gate->ensureAllowed('local');
        }

        try {
            $gate->ensureAllowed('local');
            $this->fail('the daily budget should have been spent');
        } catch (AiGateException $e) {
            $this->assertSame('rate_limited', $e->reason);
            $this->assertStringContainsString('Daily AI call budget', $e->getMessage());
        }
    }

    public function test_fifty_errors_in_an_hour_suspend_the_connection(): void
    {
        $connection = $this->connect();
        $gate = $this->gate();

        $limit = (int) config('ai.abuse.errors_per_hour');
        $this->assertSame(50, $limit);
        for ($i = 0; $i < $limit; $i++) {
            $gate->record('local', 'openrouter/free', 'error', 'upstream 500', 120);
        }

        $connection->refresh();
        $this->assertNotNull($connection->suspended_until);
        $this->assertTrue($connection->suspended_until->isFuture());

        try {
            $gate->ensureAllowed('local');
            $this->fail('a suspended connection should be refused');
        } catch (AiGateException $e) {
            $this->assertSame('suspended', $e->reason);
        }
    }

    public function test_errors_older_than_an_hour_do_not_count_toward_the_cutoff(): void
    {
        $connection = $this->connect();
        $gate = $this->gate();

        AiCall::insert(array_fill(0, 60, [
            'user_id' => 'local', 'model' => 'openrouter/free', 'status' => 'error',
            'created_at' => now()->subHours(2),
        ]));

        $gate->record('local', 'openrouter/free', 'error', 'upstream 500', 120);

        $this->assertNull($connection->refresh()->suspended_until);
    }

    public function test_a_recorded_call_stores_metadata_and_no_message_content(): void
    {
        $this->gate()->record('local', 'deepseek/deepseek-v4-flash', 'ok', null, 842, [
            'prompt_tokens' => 310, 'completion_tokens' => 96, 'total_tokens' => 406, 'cost_usd' => 0.000123,
        ]);

        $row = AiCall::sole();
        $this->assertSame('local', $row->user_id);
        $this->assertSame('deepseek/deepseek-v4-flash', $row->model);
        $this->assertSame('ok', $row->status);
        $this->assertSame(310, $row->prompt_tokens);
        $this->assertSame(96, $row->completion_tokens);
        $this->assertSame(842, $row->duration_ms);
        $this->assertSame(0.000123, $row->cost_usd);
        $this->assertNull($row->error);

        foreach ($row->getAttributes() as $column => $value) {
            $this->assertStringNotContainsString(self::PROMPT, (string) $value, "ai_calls.{$column} leaked prompt text");
            $this->assertStringNotContainsString('oversold', (string) $value, "ai_calls.{$column} leaked prompt text");
        }
    }

    public function test_usage_sums_todays_calls_for_the_current_operator(): void
    {
        $gate = $this->gate();
        $gate->record('local', 'openrouter/free', 'ok', null, 100, ['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost_usd' => 0.001]);
        $gate->record('local', 'openrouter/free', 'ok', null, 100, ['prompt_tokens' => 7, 'completion_tokens' => 3, 'cost_usd' => 0.002]);
        $gate->record('somebody-else', 'openrouter/free', 'ok', null, 100, ['prompt_tokens' => 999, 'cost_usd' => 9.99]);

        AiCall::create([
            'user_id' => 'local', 'model' => 'openrouter/free', 'status' => 'ok',
            'prompt_tokens' => 500, 'cost_usd' => 5.0, 'created_at' => now()->subDays(2),
        ]);

        $this->getJson('/api/ai/usage')->assertOk()->assertExactJson([
            'calls' => 2,
            'prompt_tokens' => 17,
            'completion_tokens' => 8,
            'cost_usd' => 0.003,
        ]);
    }
}
