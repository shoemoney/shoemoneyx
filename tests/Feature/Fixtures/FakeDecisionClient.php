<?php

declare(strict_types=1);

namespace Tests\Feature\Fixtures;

use App\Ai\Contracts\DecisionClient;
use App\Ai\DecisionResponse;

/** Scriptable fake: shifts one queued DecisionResponse (or throws a queued Throwable) per decide() call, records every call's args. */
final class FakeDecisionClient implements DecisionClient
{
    /** @var list<array{userKey: string, state: array, questions: array, model: string}> */
    public array $calls = [];

    /** @param list<DecisionResponse|\Throwable> $responses */
    public function __construct(private array $responses) {}

    public function decide(string $userKey, array $state, array $questions, string $model): DecisionResponse
    {
        $this->calls[] = compact('userKey', 'state', 'questions', 'model');

        $next = array_shift($this->responses) ?? new DecisionResponse([], 'fake');
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
