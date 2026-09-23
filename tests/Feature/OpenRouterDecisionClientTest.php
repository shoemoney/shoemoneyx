<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Ai\Contracts\DecisionClient;
use App\Ai\DecisionQuestion;
use App\Ai\Exceptions\AiNoConnectionException;
use App\Ai\OpenRouterDecisionClient;
use App\Models\AiCall;
use App\Models\AiConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class OpenRouterDecisionClientTest extends TestCase
{
    use RefreshDatabase;

    private const DECISIONS = 'openrouter.ai/api/alpha/decisions';

    private function connect(): void
    {
        AiConnection::create([
            'user_id' => 'local', 'provider' => 'openrouter',
            'key' => 'sk-or-v1-connected', 'connected_at' => now(),
        ]);
    }

    private function client(): OpenRouterDecisionClient
    {
        return app(OpenRouterDecisionClient::class);
    }

    public function test_the_contract_resolves_to_the_openrouter_decision_client(): void
    {
        $this->assertInstanceOf(OpenRouterDecisionClient::class, app(DecisionClient::class));
    }

    public function test_it_sends_state_verbatim_and_parses_a_noul_answer(): void
    {
        $this->connect();
        Http::fake([self::DECISIONS => Http::response([
            'model' => 'jev-1.13-20260917',
            'answers' => ['intent_match' => ['type' => 'noul', 'noul' => 0.13]],
            'usage' => ['input_tokens' => 410, 'output_tokens' => 68, 'cost' => 0.00001722],
        ])]);

        $state = ['key' => 'rsi-dip', 'meta' => ['description' => 'buys the dip']];
        $reply = $this->client()->decide(
            'local', $state, ['intent_match' => new DecisionQuestion('Do the rules match the description?')], 'typesafe/jev-1.13',
        );

        $this->assertSame(0.13, $reply->probability('intent_match'));
        $this->assertSame('jev-1.13-20260917', $reply->model);
        $this->assertSame(410, $reply->usage['prompt_tokens']);
        $this->assertSame(68, $reply->usage['completion_tokens']);
        $this->assertSame(478, $reply->usage['total_tokens']);
        $this->assertSame(0.00001722, $reply->usage['cost_usd']);

        Http::assertSent(function ($request) use ($state) {
            return $request['model'] === 'typesafe/jev-1.13'
                && $request['state'] === $state
                && $request['questions']['intent_match']['type'] === 'noul'
                && $request['questions']['intent_match']['instructions'] === 'Do the rules match the description?'
                && $request->hasHeader('Authorization', 'Bearer sk-or-v1-connected');
        });

        $call = AiCall::sole();
        $this->assertSame('ok', $call->status);
        $this->assertSame('jev-1.13-20260917', $call->model);
        $this->assertSame(410, $call->prompt_tokens);
    }

    public function test_a_missing_answers_object_yields_a_response_with_no_probability(): void
    {
        $this->connect();
        Http::fake([self::DECISIONS => Http::response(['model' => 'jev-1.13-20260917'])]);

        $reply = $this->client()->decide('local', [], ['intent_match' => new DecisionQuestion('q')], 'typesafe/jev-1.13');

        $this->assertNull($reply->probability('intent_match'));
    }

    public function test_a_non_numeric_noul_yields_a_response_with_no_probability(): void
    {
        $this->connect();
        Http::fake([self::DECISIONS => Http::response([
            'model' => 'jev-1.13-20260917',
            'answers' => ['intent_match' => ['type' => 'noul', 'noul' => 'not-a-number']],
        ])]);

        $reply = $this->client()->decide('local', [], ['intent_match' => new DecisionQuestion('q')], 'typesafe/jev-1.13');

        $this->assertNull($reply->probability('intent_match'));
    }

    public function test_it_records_an_error_row_and_throws_on_an_upstream_failure(): void
    {
        $this->connect();
        Http::fake([self::DECISIONS => Http::response(['error' => 'boom'], 500)]);

        $this->expectException(\RuntimeException::class);
        try {
            $this->client()->decide('local', [], ['intent_match' => new DecisionQuestion('q')], 'typesafe/jev-1.13');
        } finally {
            $this->assertSame('error', AiCall::sole()->status);
        }
    }

    public function test_a_timeout_records_an_error_row_and_throws_without_retrying(): void
    {
        $this->connect();
        Sleep::fake();
        Http::fake([self::DECISIONS => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

        try {
            $this->client()->decide('local', [], ['intent_match' => new DecisionQuestion('q')], 'typesafe/jev-1.13');
            $this->fail('expected a ConnectionException');
        } catch (ConnectionException) {
            // expected: the client rethrows, it does not swallow transport failures itself
        }

        // A connection failure rejects the transport promise before Http's own recorder
        // middleware runs (its `.then()` has no rejection handler), so assertSentCount can't
        // see this attempt either — true of a real timeout, not just this faked one. The gate
        // row and the absence of a retry sleep are what this test can actually observe.
        $this->assertSame('error', AiCall::sole()->status);
        Sleep::assertNeverSlept();
    }

    public function test_it_never_retries_on_429(): void
    {
        $this->connect();
        Sleep::fake();
        Http::fake([self::DECISIONS => Http::response(['error' => 'slow down'], 429)]);

        $this->expectException(\RuntimeException::class);
        try {
            $this->client()->decide('local', [], ['intent_match' => new DecisionQuestion('q')], 'typesafe/jev-1.13');
        } finally {
            Http::assertSentCount(1);
            Sleep::assertNeverSlept();
        }
    }

    public function test_it_throws_before_any_request_when_no_key_is_resolvable(): void
    {
        config()->set('services.openrouter.key', null);
        Http::preventStrayRequests();

        $this->expectException(AiNoConnectionException::class);
        $this->client()->decide('local', [], ['intent_match' => new DecisionQuestion('q')], 'typesafe/jev-1.13');
    }
}
