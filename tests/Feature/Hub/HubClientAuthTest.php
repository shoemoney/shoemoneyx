<?php

declare(strict_types=1);

namespace Tests\Feature\Hub;

use App\Hub\HubClient;
use App\Hub\HubConnection;
use App\Hub\HubException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HubClientAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.url' => 'https://hub.test']);
    }

    private function strategyResponse(): array
    {
        return [
            'strategy' => ['id' => 1, 'slug' => 'mr-h1'],
            'author' => ['handle' => 'shoemoney'],
            'current_version' => '1.0.0',
            'versions' => [],
            'stats' => ['stars' => 0, 'imports' => 0, 'comments' => 0],
        ];
    }

    public function test_no_authorization_header_is_sent_when_there_is_no_hub_connection(): void
    {
        Http::fake(['hub.test/*' => Http::response($this->strategyResponse())]);

        app(HubClient::class)->strategy('mr-h1');

        Http::assertSent(fn (HttpRequest $request) => ! $request->hasHeader('Authorization'));
    }

    public function test_authorization_header_carries_the_active_connections_token_when_connected(): void
    {
        HubConnection::create([
            'user_handle' => 'shoemoney',
            'desk_id' => null,
            'token' => 'plaintext-token-abc',
            'connected_at' => now(),
            'revoked_at' => null,
        ]);

        Http::fake(['hub.test/*' => Http::response($this->strategyResponse())]);

        app(HubClient::class)->strategy('mr-h1');

        Http::assertSent(fn (HttpRequest $request) => $request->header('Authorization')[0] === 'Bearer plaintext-token-abc');
    }

    public function test_rate_limited_retries_once_then_throws_when_still_rate_limited(): void
    {
        Http::fake(['hub.test/*' => Http::sequence()
            ->push(['error' => ['code' => 'rate_limited', 'message' => 'slow down', 'retry_after' => 0]])
            ->push(['error' => ['code' => 'rate_limited', 'message' => 'slow down', 'retry_after' => 0]]),
        ]);

        try {
            app(HubClient::class)->strategy('mr-h1');
            $this->fail('expected a HubException to be thrown');
        } catch (HubException $e) {
            $this->assertSame('rate_limited', $e->code);
        }

        Http::assertSentCount(2);
    }

    public function test_rate_limited_retries_once_then_returns_the_success_body(): void
    {
        Http::fake(['hub.test/*' => Http::sequence()
            ->push(['error' => ['code' => 'rate_limited', 'message' => 'slow down', 'retry_after' => 0]])
            ->push($this->strategyResponse()),
        ]);

        $result = app(HubClient::class)->strategy('mr-h1');

        $this->assertSame($this->strategyResponse(), $result);
        Http::assertSentCount(2);
    }

    public function test_a_non_rate_limited_error_envelope_throws_immediately_with_no_retry(): void
    {
        Http::fake(['hub.test/*' => Http::response(['error' => ['code' => 'not_found', 'message' => 'no such strategy']])]);

        try {
            app(HubClient::class)->strategy('missing');
            $this->fail('expected a HubException to be thrown');
        } catch (HubException $e) {
            $this->assertSame('not_found', $e->code);
            $this->assertSame('no such strategy', $e->getMessage());
        }

        Http::assertSentCount(1);
    }
}
