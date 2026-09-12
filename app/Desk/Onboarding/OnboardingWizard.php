<?php

declare(strict_types=1);

namespace App\Desk\Onboarding;

use App\Desk\Settings;

/**
 * First-run wizard state machine: no key -> paper trading. Five ordered steps, persisted
 * server-side under Settings['onboarding_state'] (so it survives a restart and resumes
 * where it left off), each in one of three states — pending, done, skipped. A step only
 * accepts a submission once every step ahead of it in ORDER is done or skipped; that is
 * the whole "in order" contract, enforced once here instead of per-controller-action.
 * Only 'strategy-import' is skippable — the others must be explicitly settled (an explicit
 * empty master password still counts as "done", it just means "no password, trusted local
 * desk"), matching the product rule that only the community-import step may be bypassed.
 */
final class OnboardingWizard
{
    public const ORDER = ['master-password', 'openrouter', 'exchange', 'strategy-import', 'launch'];

    private const SKIPPABLE = ['strategy-import'];

    private const SETTINGS_KEY = 'onboarding_state';

    public function __construct(private readonly Settings $settings) {}

    /** @return array{completed: bool, next_step: ?string, steps: list<array{key: string, status: string, skippable: bool}>, require_master_password: bool, bootstrap: bool} */
    public function state(): array
    {
        $stored = $this->stored();
        $steps = array_map(fn (string $key) => [
            'key' => $key,
            'status' => $stored[$key]['status'] ?? 'pending',
            'skippable' => in_array($key, self::SKIPPABLE, true),
        ], self::ORDER);

        $next = collect($steps)->first(fn (array $s) => $s['status'] === 'pending');

        return [
            'completed' => $next === null,
            'next_step' => $next['key'] ?? null,
            'steps' => $steps,
            // Lets the SPA's master-password step adapt without a separate round trip.
            'require_master_password' => (bool) config('desk.require_master_password'),
            'bootstrap' => $this->settings->masterPasswordIsBootstrap(),
        ];
    }

    public function status(string $step): ?string
    {
        return $this->stored()[$step]['status'] ?? (in_array($step, self::ORDER, true) ? 'pending' : null);
    }

    /** The first step (in order) not yet done or skipped — null once every step is settled. */
    public function nextStep(): ?string
    {
        return $this->state()['next_step'];
    }

    public function isComplete(): bool
    {
        return $this->state()['completed'];
    }

    /**
     * True when every step strictly before $step in ORDER is done or skipped — the gate a
     * controller checks before acting on $step at all.
     */
    public function readyFor(string $step): bool
    {
        $index = array_search($step, self::ORDER, true);
        if ($index === false) {
            return false;
        }

        foreach (array_slice(self::ORDER, 0, $index) as $prior) {
            if (! in_array($this->status($prior), ['done', 'skipped'], true)) {
                return false;
            }
        }

        return true;
    }

    public function markDone(string $step, array $meta = []): void
    {
        $this->write($step, 'done', $meta);
    }

    public function markSkipped(string $step, array $meta = []): void
    {
        $this->write($step, 'skipped', $meta);
    }

    /** @return array<string, array{status: string}> */
    private function stored(): array
    {
        $state = (array) $this->settings->get(self::SETTINGS_KEY, []);

        return (array) ($state['steps'] ?? []);
    }

    private function write(string $step, string $status, array $meta): void
    {
        $state = (array) $this->settings->get(self::SETTINGS_KEY, []);
        $state['steps'][$step] = ['status' => $status, 'at' => now()->toIso8601String(), ...$meta];
        $this->settings->set(self::SETTINGS_KEY, $state);
    }
}
