<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ai\CurrentUser;
use App\Ai\OpenRouterOAuth;
use App\Models\AiConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AiConnectController extends Controller
{
    private const SESSION_VERIFIER = 'ai.openrouter.verifier';

    private const SESSION_STATE = 'ai.openrouter.state';

    public function __construct(private readonly OpenRouterOAuth $oauth) {}

    public function connect(Request $request): RedirectResponse
    {
        $verifier = $this->oauth->generateVerifier();
        $state = bin2hex(random_bytes(16));

        $request->session()->put(self::SESSION_VERIFIER, $verifier);
        $request->session()->put(self::SESSION_STATE, $state);

        // OpenRouter has no `state` parameter of its own — it appends `&code=` to whatever
        // callback_url it is handed — so the CSRF token rides back in our own querystring.
        $callback = url('/ai/openrouter/callback').'?state='.$state;

        return redirect()->away($this->oauth->authorizeUrl($callback, $this->oauth->challengeFor($verifier)));
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $request->session()->pull(self::SESSION_STATE);
        $verifier = $request->session()->pull(self::SESSION_VERIFIER);

        if (! is_string($state) || ! is_string($verifier) || ! hash_equals($state, (string) $request->query('state'))) {
            abort(419, 'This OpenRouter connect link expired. Start again from the builder.');
        }

        $code = (string) $request->query('code');
        if ($code === '') {
            abort(400, 'OpenRouter did not return an authorization code.');
        }

        try {
            $key = $this->oauth->exchangeCode($code, $verifier);
        } catch (\RuntimeException $e) {
            abort(502, $e->getMessage());
        }

        AiConnection::updateOrCreate(
            ['user_id' => CurrentUser::key(), 'provider' => 'openrouter'],
            [
                'key' => $key,
                'label' => $this->oauth->fetchKeyInfo($key)['label'] ?? null,
                'connected_at' => now(),
                'revoked_at' => null,
                'suspended_until' => null,
            ],
        );

        return redirect('/builder');
    }
}
