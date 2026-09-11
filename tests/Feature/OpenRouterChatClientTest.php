<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Ai\Contracts\ChatClient;
use App\Ai\Exceptions\AiNoConnectionException;
use App\Ai\OpenRouterChatClient;
use App\Models\AiCall;
use App\Models\AiConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class OpenRouterChatClientTest extends TestCase
{
    use RefreshDatabase;

    private const COMPLETIONS = 'openrouter.ai/api/v1/chat/completions';

    private function connect(): void
    {
        AiConnection::create([
            'user_id' => 'local', 'provider' => 'openrouter',
            'key' => 'sk-or-v1-connected', 'connected_at' => now(),
        ]);
    }

    private function client(): OpenRouterChatClient
    {
        return app(OpenRouterChatClient::class);
    }

    public function test_the_contract_resolves_to_the_openrouter_client(): void
    {
        $this->assertInstanceOf(OpenRouterChatClient::class, app(ChatClient::class));
        $this->assertSame('openrouter/free', app(ChatClient::class)->defaultModel());
    }

    public function test_it_decodes_tool_calls_into_argument_arrays(): void
    {
        $this->connect();
        Http::fake([self::COMPLETIONS => Http::response([
            'model' => 'deepseek/deepseek-v4-flash',
            'choices' => [[
                'finish_reason' => 'tool_calls',
                'message' => [
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_7hx',
                        'type' => 'function',
                        'function' => [
                            'name' => 'run_backtest',
                            'arguments' => '{"plugin_key":"rsi-dip","days":30,"products":["BTC-USD"]}',
                        ],
                    ]],
                ],
            ]],
            'usage' => ['prompt_tokens' => 412, 'completion_tokens' => 37, 'total_tokens' => 449, 'cost' => 0.000217],
        ])]);

        $reply = $this->client()->chat(
            [['role' => 'user', 'content' => 'backtest rsi-dip']],
            [['type' => 'function', 'function' => ['name' => 'run_backtest', 'description' => 'run it', 'parameters' => []]]],
        );

        $this->assertNull($reply->content);
        $this->assertTrue($reply->hasToolCalls());
        $this->assertCount(1, $reply->toolCalls);
        $this->assertSame('call_7hx', $reply->toolCalls[0]['id']);
        $this->assertSame('run_backtest', $reply->toolCalls[0]['name']);
        $this->assertSame(
            ['plugin_key' => 'rsi-dip', 'days' => 30, 'products' => ['BTC-USD']],
            $reply->toolCalls[0]['arguments'],
        );
        $this->assertSame('deepseek/deepseek-v4-flash', $reply->model);
        $this->assertSame('tool_calls', $reply->finishReason);
        $this->assertSame(412, $reply->usage['prompt_tokens']);
        $this->assertSame(0.000217, $reply->usage['cost_usd']);

        Http::assertSent(function ($request) {
            return $request['model'] === 'openrouter/free'
                && $request['tools'][0]['function']['name'] === 'run_backtest'
                && $request->hasHeader('Authorization', 'Bearer sk-or-v1-connected')
                && $request->hasHeader('X-Title', 'shoemoneyx strategy builder');
        });

        $call = AiCall::sole();
        $this->assertSame('ok', $call->status);
        $this->assertSame('deepseek/deepseek-v4-flash', $call->model);
        $this->assertSame(412, $call->prompt_tokens);
    }

    public function test_it_records_an_error_row_and_throws_on_an_upstream_failure(): void
    {
        $this->connect();
        Http::fake([self::COMPLETIONS => Http::response(['error' => 'boom'], 500)]);

        $this->expectException(\RuntimeException::class);
        try {
            $this->client()->chat([['role' => 'user', 'content' => 'hi']]);
        } finally {
            $this->assertSame('error', AiCall::sole()->status);
        }
    }

    public function test_it_backs_off_once_on_429_and_does_not_loop(): void
    {
        $this->connect();
        Sleep::fake();
        Http::fakeSequence()
            ->push(['error' => 'slow down'], 429)
            ->push(['model' => 'openrouter/free', 'choices' => [['message' => ['content' => 'ok now']]]], 200);

        $reply = $this->client()->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('ok now', $reply->content);
        Sleep::assertSleptTimes(1);
        Http::assertSentCount(2);
    }

    public function test_it_falls_back_to_the_env_key_when_no_account_is_connected(): void
    {
        config()->set('services.openrouter.key', 'sk-or-v1-selfhost');
        Http::fake([self::COMPLETIONS => Http::response(['model' => 'openrouter/free', 'choices' => [['message' => ['content' => 'hi']]]])]);

        $this->assertSame('hi', $this->client()->chat([['role' => 'user', 'content' => 'hi']])->content);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-or-v1-selfhost'));
    }

    public function test_it_throws_when_no_key_is_resolvable(): void
    {
        config()->set('services.openrouter.key', null);

        $this->expectException(AiNoConnectionException::class);
        $this->client()->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function test_assist_returns_the_reply_through_the_connected_account(): void
    {
        $this->connect();
        Http::fake([self::COMPLETIONS => Http::response([
            'model' => 'openrouter/free',
            'choices' => [['message' => ['content' => '```json\n{}\n```']]],
        ])]);

        $this->postJson('/api/strategy-assist', ['messages' => [['role' => 'user', 'content' => 'draft me a dip buyer']]])
            ->assertOk()
            ->assertJson(['model' => 'openrouter/free']);

        Http::assertSent(fn ($request) => $request['messages'][0]['role'] === 'system');
    }

    public function test_assist_maps_a_rate_limit_to_429(): void
    {
        $this->connect();
        Http::preventStrayRequests();
        config()->set('ai.rate_limit.per_minute', 1);
        RateLimiter::hit('ai:rate:minute:local', 60);

        $this->postJson('/api/strategy-assist', ['messages' => [['role' => 'user', 'content' => 'hi']]])
            ->assertStatus(429);
    }

    public function test_assist_maps_a_suspension_to_403(): void
    {
        $this->connect();
        Http::preventStrayRequests();
        AiConnection::sole()->update(['suspended_until' => now()->addHour()]);

        $this->postJson('/api/strategy-assist', ['messages' => [['role' => 'user', 'content' => 'hi']]])
            ->assertStatus(403);
    }

    public function test_assist_maps_an_upstream_failure_to_502(): void
    {
        $this->connect();
        Http::fake([self::COMPLETIONS => Http::response(['error' => 'boom'], 500)]);

        $this->postJson('/api/strategy-assist', ['messages' => [['role' => 'user', 'content' => 'hi']]])
            ->assertStatus(502);
    }
}
