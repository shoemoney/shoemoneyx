<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Ai\CurrentUser;
use App\Ai\OpenRouterOAuth;
use App\Desk\Chief;
use App\Desk\DeskAuthThrottle;
use App\Desk\DeskLogin;
use App\Desk\Onboarding\OnboardingWizard;
use App\Desk\Settings;
use App\Exchange\ExchangeRegistry;
use App\Http\Controllers\Controller;
use App\Models\AiConnection;
use App\Strategies\Sync\StrategySync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * First-run wizard: no key -> paper trading, in under five minutes. Each step but
 * strategy-import must be settled in order — OnboardingWizard::readyFor() is the gate.
 */
class OnboardingController extends Controller
{
    public function show(OnboardingWizard $wizard): JsonResponse
    {
        return response()->json($wizard->state());
    }

    public function update(string $step, Request $request, OnboardingWizard $wizard): JsonResponse
    {
        if (! in_array($step, OnboardingWizard::ORDER, true)) {
            abort(404);
        }

        if (! $wizard->readyFor($step)) {
            return response()->json(['ok' => false, 'error' => 'earlier steps are not settled yet', 'next_step' => $wizard->nextStep()], 409);
        }

        return match ($step) {
            'master-password' => $this->masterPassword($request, $wizard),
            'openrouter' => $this->openrouter($request, $wizard),
            'exchange' => $this->exchange($request, $wizard),
            'strategy-import' => $this->strategyImport($request, $wizard),
            'launch' => $this->launch($wizard),
        };
    }

    private function masterPassword(Request $request, OnboardingWizard $wizard): JsonResponse
    {
        $settings = app(Settings::class);

        if (DeskAuthThrottle::tooManyFailures($request)) {
            DeskAuthThrottle::lockout($request);
        }

        $changing = ! $settings->needsPasswordSetup();
        $rules = ['password' => 'required|string|min:12|max:200|confirmed'];
        if ($changing) {
            $rules['current_password'] = 'required|string|max:200';
        }
        $data = $request->validate($rules, [
            'password.min' => 'Choose a password of at least 12 characters.',
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        if ($changing && ! $settings->verifyMasterPassword($data['current_password'])) {
            DeskAuthThrottle::recordFailure($request);

            throw ValidationException::withMessages(['current_password' => 'The current password is wrong.']);
        }

        if ($settings->isBootstrapKey($data['password'])) {
            throw ValidationException::withMessages(['password' => 'Choose a password different from the first-login key.']);
        }

        $settings->setMasterPassword($data['password']);
        DeskLogin::grant($request->session());
        $wizard->markDone('master-password', ['password_set' => true]);

        return response()->json(['ok' => true, 'step' => 'master-password', 'status' => 'done', 'master_password_set' => true, 'csrf_token' => $request->session()->token()]);
    }

    private function openrouter(Request $request, OnboardingWizard $wizard): JsonResponse
    {
        $data = $request->validate(['api_key' => 'required|string']);
        $apiKey = $data['api_key'];

        $info = app(OpenRouterOAuth::class)->fetchKeyInfo($apiKey);
        if ($info === null) {
            return response()->json(['ok' => false, 'error' => 'OpenRouter did not recognize that key.'], 422);
        }

        AiConnection::updateOrCreate(
            ['user_id' => CurrentUser::key(), 'provider' => 'openrouter'],
            ['key' => $apiKey, 'label' => $info['label'] ?? null, 'connected_at' => now(), 'revoked_at' => null, 'suspended_until' => null],
        );
        $wizard->markDone('openrouter', ['label' => $info['label'] ?? null]);

        return response()->json(['ok' => true, 'step' => 'openrouter', 'status' => 'done', 'label' => $info['label'] ?? null]);
    }

    private function exchange(Request $request, OnboardingWizard $wizard): JsonResponse
    {
        $data = $request->validate(['exchange' => 'required|string']);
        $id = $data['exchange'];

        if (! in_array($id, app(ExchangeRegistry::class)->all(), true)) {
            return response()->json(['ok' => false, 'error' => 'unknown exchange'], 422);
        }

        app(Settings::class)->set('exchange', $id);
        app(Settings::class)->set('mode', 'paper');
        $wizard->markDone('exchange', ['exchange' => $id]);

        return response()->json(['ok' => true, 'step' => 'exchange', 'status' => 'done', 'exchange' => $id, 'mode' => 'paper']);
    }

    private function strategyImport(Request $request, OnboardingWizard $wizard): JsonResponse
    {
        if ($request->boolean('skip')) {
            $wizard->markSkipped('strategy-import');

            return response()->json(['ok' => true, 'step' => 'strategy-import', 'status' => 'skipped']);
        }

        $remoteId = $request->input('remote_id');
        if (! is_string($remoteId) || $remoteId === '') {
            return response()->json(['ok' => false, 'error' => 'remote_id or skip required'], 422);
        }

        try {
            $result = app(StrategySync::class)->import($remoteId);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }

        if ($result['imported'] !== true) {
            return response()->json(['ok' => false, 'error' => $result['error'] ?? 'import failed'], 502);
        }

        $wizard->markDone('strategy-import', ['imported' => $remoteId]);

        return response()->json(['ok' => true, 'step' => 'strategy-import', 'status' => 'done', 'imported' => $remoteId]);
    }

    private function launch(OnboardingWizard $wizard): JsonResponse
    {
        app(Settings::class)->set('mode', 'paper');
        app(Chief::class)->setRunning(true);
        $wizard->markDone('launch');

        return response()->json([
            'ok' => true, 'step' => 'launch', 'status' => 'done', 'mode' => 'paper',
            'running' => true, 'completed' => $wizard->isComplete(),
        ]);
    }
}
