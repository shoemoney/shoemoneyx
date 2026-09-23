<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Ai\Contracts\DecisionClient;
use App\Ai\DecisionResponse;
use App\Desk\Strategies\DefinitionCheck;
use App\Models\AiConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fixtures\FakeDecisionClient;
use Tests\TestCase;

class DefinitionCheckTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> a schema-valid v2 definition, per SchemaMigratorTest */
    private function v2Definition(array $overrides = []): array
    {
        $definition = json_decode(
            file_get_contents(base_path('resources/strategies/examples/smx-pi-take-profit-v2.json')),
            true,
        );

        return array_replace_recursive($definition, $overrides);
    }

    private function connect(): void
    {
        AiConnection::create([
            'user_id' => 'local', 'provider' => 'openrouter',
            'key' => 'sk-or-v1-connected', 'connected_at' => now(),
        ]);
    }

    /** @param DecisionResponse|\Throwable ...$responses */
    private function fake(...$responses): FakeDecisionClient
    {
        $fake = new FakeDecisionClient($responses);
        $this->app->instance(DecisionClient::class, $fake);

        return $fake;
    }

    public function test_no_key_connected_produces_no_advisory_and_makes_no_call(): void
    {
        $fake = $this->fake(new DecisionResponse(['intent_match' => 0.05], 'jev-1.13'));

        $result = app(DefinitionCheck::class)->run($this->v2Definition());

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame([], $fake->calls);
    }

    public function test_a_transport_failure_produces_no_advisory_and_does_not_bubble(): void
    {
        $this->connect();
        $fake = $this->fake(new \RuntimeException('cURL error 28: Operation timed out'));

        $result = app(DefinitionCheck::class)->run($this->v2Definition(['key' => 'transport-failure']));

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['warnings']);
        $this->assertCount(1, $fake->calls);
    }

    public function test_a_missing_answer_key_produces_no_advisory(): void
    {
        $this->connect();
        $this->fake(new DecisionResponse(['some_other_id' => 0.05], 'jev-1.13'));

        $result = app(DefinitionCheck::class)->run($this->v2Definition(['key' => 'missing-answer']));

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_exactly_at_the_threshold_does_not_warn(): void
    {
        $this->connect();
        $this->fake(new DecisionResponse(['intent_match' => DefinitionCheck::THRESHOLD], 'jev-1.13'));

        $result = app(DefinitionCheck::class)->run($this->v2Definition(['key' => 'boundary-at']));

        $this->assertSame([], $result['warnings']);
    }

    public function test_just_under_the_threshold_warns(): void
    {
        $this->connect();
        $this->fake(new DecisionResponse(['intent_match' => DefinitionCheck::THRESHOLD - 0.01], 'jev-1.13'));

        $result = app(DefinitionCheck::class)->run($this->v2Definition(['key' => 'boundary-under']));

        $this->assertSame('meta.description', $result['warnings'][0]['path']);
    }

    public function test_a_legacy_definition_is_never_sent_to_jev(): void
    {
        $this->connect();
        $fake = $this->fake(new DecisionResponse(['intent_match' => 0.01], 'jev-1.13'));

        $result = app(DefinitionCheck::class)->run([
            'key' => 'legacy-strat', 'name' => 'Legacy', 'version' => 1, 'description' => 'buys the dip',
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $fake->calls);
    }

    public function test_a_v1_definition_is_never_sent_to_jev(): void
    {
        $this->connect();
        $fake = $this->fake(new DecisionResponse(['intent_match' => 0.01], 'jev-1.13'));

        $result = app(DefinitionCheck::class)->run([
            'schema_version' => 1, 'key' => 'v1-strat',
            'meta' => ['name' => 'V1', 'description' => 'buys the dip'],
            'entry' => ['side' => 'long'],
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $fake->calls);
    }

    public function test_an_empty_description_is_never_sent_to_jev(): void
    {
        $this->connect();
        $fake = $this->fake(new DecisionResponse(['intent_match' => 0.01], 'jev-1.13'));

        $definition = $this->v2Definition(['key' => 'empty-description']);
        $definition['meta']['description'] = '   ';

        app(DefinitionCheck::class)->run($definition);

        $this->assertSame([], $fake->calls);
    }

    public function test_a_warning_is_produced_below_threshold_and_is_shaped_like_a_schema_warning(): void
    {
        $this->connect();
        $this->fake(new DecisionResponse(['intent_match' => 0.13], 'jev-1.13'));

        $result = app(DefinitionCheck::class)->run($this->v2Definition(['key' => 'below-threshold']));

        $this->assertTrue($result['valid']);
        $this->assertSame('meta.description', $result['warnings'][0]['path']);
        $this->assertStringContainsString('0.13', $result['warnings'][0]['message']);
    }

    public function test_the_save_path_is_unaffected_when_the_lint_fails(): void
    {
        $this->connect();
        $this->fake(new \RuntimeException('boom'));

        $this->postJson('/api/strategy-plugins', ['definition' => $this->v2Definition(['key' => 'save-unaffected'])])
            ->assertCreated()
            ->assertJson(['valid' => true, 'warnings' => []]);
    }
}
