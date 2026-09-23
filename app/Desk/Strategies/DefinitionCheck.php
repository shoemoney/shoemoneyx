<?php

declare(strict_types=1);

namespace App\Desk\Strategies;

use App\Ai\Contracts\DecisionClient;
use App\Ai\CurrentUser;
use App\Ai\DecisionQuestion;
use App\Ai\OpenRouterKey;
use Illuminate\Support\Facades\Cache;

/**
 * Everything the desk can say about a definition before it is written: schema
 * errors and warnings from the pure validators (SchemaMigrator::validateForSave,
 * untouched and still synchronous), plus the intent-drift advisory from Jev.
 *
 * run() takes exactly one argument. That is what keeps a backtest statistic or a
 * viability question from ever reaching Jev — there is no parameter to carry one
 * through, and the question actually sent is a private constant. Everything on
 * the Jev path fails soft: no key, a disabled lint, a schema-invalid or legacy
 * definition, an empty description, a gate refusal, a transport failure, or a
 * reply with no usable answer all collapse to "no advisory", never an exception
 * and never a change to $valid.
 */
final class DefinitionCheck
{
    /** Pinned with THRESHOLD and QUESTION: the three move together or not at all. */
    public const MODEL = 'typesafe/jev-1.13';

    /**
     * Below this the answer becomes a warning. CALIBRATED, not a knob: measured
     * over 24 live calls sending the entire strategy definition as `state`.
     * Matched (rules, description) pairs scored 0.24-0.79; description-swapped
     * pairs scored 0.05-0.45. 0.20 gives zero false alarms on all 12 real
     * strategies. Sending a subset, a summary, or a differently shaped payload
     * invalidates this number.
     */
    public const THRESHOLD = 0.20;

    /** Calibrated wording, verbatim. Changing a word re-opens the 24-call measurement. */
    private const QUESTION = 'Do the entry rules actually implement the behaviour described in '
        .'`meta.description`? Answer yes only if a trader reading the description would '
        .'correctly predict when this strategy opens a position.';

    private const ANSWER_ID = 'intent_match';

    public function __construct(
        private readonly DecisionClient $decisions,
        private readonly OpenRouterKey $keys,
    ) {}

    /** @return array{valid: bool, errors: list<array{path: string, message: string}>, warnings: list<array{path: string, message: string}>} */
    public function run(array $definition): array
    {
        $schema = SchemaMigrator::validateForSave($definition);
        $warnings = $schema['warnings'] ?? []; // JsonPluginValidator (legacy) has no warnings key

        $probability = $schema['valid'] ? $this->intentMatch($definition) : null;
        if ($probability !== null && $probability < self::THRESHOLD) {
            $warnings[] = [
                'path' => 'meta.description',
                'message' => sprintf(
                    'The rules may not do what the description says (intent match %.2f). '
                        .'Re-read the rules against the description before trusting a backtest.',
                    $probability,
                ),
            ];
        }

        return ['valid' => (bool) $schema['valid'], 'errors' => $schema['errors'] ?? [], 'warnings' => $warnings];
    }

    /**
     * Fail-soft probability, memoised by definition content and MODEL. Null means
     * "no opinion" for every reason listed on the class doc above. Failures are
     * never cached, so the next click retries a transient outage naturally.
     */
    private function intentMatch(array $definition): ?float
    {
        // The threshold was measured only against v2's shape (grounding: the one v1
        // calibration file scored nearest the line). Legacy and v1 definitions get no
        // advisory, silently.
        if ((int) ($definition['schema_version'] ?? 0) < 2) {
            return null;
        }

        if (trim((string) ($definition['meta']['description'] ?? '')) === '') {
            return null;
        }

        if (! (bool) config('ai.intent_lint.enabled', true)) {
            return null;
        }

        $cacheKey = 'intent-lint:'.self::MODEL.':'.hash('sha256', json_encode($definition));
        $cached = Cache::get($cacheKey);
        if (is_float($cached)) {
            return $cached;
        }

        $userKey = CurrentUser::key();
        if (! $this->keys->exists($userKey)) {
            return null;
        }

        try {
            $probability = $this->decisions
                ->decide($userKey, $definition, [self::ANSWER_ID => new DecisionQuestion(self::QUESTION)], self::MODEL)
                ->probability(self::ANSWER_ID);
        } catch (\Throwable) {
            return null;
        }

        if ($probability !== null) {
            Cache::put($cacheKey, $probability, (int) config('ai.intent_lint.cache_ttl_seconds', 3600));
        }

        return $probability;
    }
}
