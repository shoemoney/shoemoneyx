<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\DecisionQuestion;
use App\Ai\DecisionResponse;
use App\Ai\Exceptions\AiGateException;
use App\Ai\Exceptions\AiNoConnectionException;

/**
 * One decision call against the user's connected provider. Sibling of ChatClient.
 *
 * $state is whatever the caller wants judged, sent verbatim as the wire "state" —
 * callers must not reshape or subset it, since a decision model's calibration is
 * bound to the exact shape it was measured against. $questions are keyed by the id
 * the answer comes back under.
 *
 * $userKey is explicit, unlike ChatClient's implicit CurrentUser::key(): a caller
 * without an HTTP request (a queue worker, say) must not silently resolve to 'local'.
 *
 * $model is required: decision models are pinned by their callers because a
 * threshold is calibrated per model and per state shape, and there is no
 * catalogue to discover one from.
 *
 * Never sleeps and never retries: a caller sitting inside a user-facing request
 * must not stall on a flaky upstream. Callers that need fail-soft behaviour
 * catch these themselves.
 */
interface DecisionClient
{
    /**
     * @param  non-empty-array<string, DecisionQuestion>  $questions
     *
     * @throws AiNoConnectionException no key resolvable for $userKey
     * @throws AiGateException the gate refused the call
     * @throws \RuntimeException transport failure or a non-2xx response
     */
    public function decide(string $userKey, array $state, array $questions, string $model): DecisionResponse;
}
