<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\Prompts\StrategyBuilderPrompt;
use Tests\TestCase;

class StrategyBuilderPromptTest extends TestCase
{
    public function test_prompt_contains_all_seven_phase_headings(): void
    {
        $prompt = StrategyBuilderPrompt::system();

        foreach ([
            '### Phase 1 — Setup',
            '### Phase 2 — Trigger',
            '### Phase 3 — Entry',
            '### Phase 4 — Management',
            '### Phase 5 — Exit',
            '### Phase 6 — Risk',
            '### Phase 7 — Review',
        ] as $heading) {
            $this->assertStringContainsString($heading, $prompt);
        }
    }

    public function test_prompt_embeds_the_live_schema_doc(): void
    {
        $prompt = StrategyBuilderPrompt::system();

        $this->assertStringContainsString('"schema_version": 1,', $prompt);
        $this->assertStringContainsString('## Top-level keys', $prompt);
    }
}
