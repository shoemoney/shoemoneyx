<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Exceptions\AiNoConnectionException;
use App\Models\AiConnection;

/**
 * Where an OpenRouter key comes from, for every client: a connected account
 * first, then the self-host env key. Previously duplicated between
 * OpenRouterChatClient's private resolveKey() and the inline precedence
 * check at AiController::status().
 */
final class OpenRouterKey
{
    /**
     * Touches last_used_at on a connected account: this is a USE.
     *
     * @throws AiNoConnectionException when neither source has a key
     */
    public function require(string $userKey): string
    {
        $connection = AiConnection::activeFor($userKey);
        if ($connection !== null) {
            $connection->forceFill(['last_used_at' => now()])->save();

            return $connection->key;
        }

        $envKey = (string) config('services.openrouter.key');
        if ($envKey !== '') {
            return $envKey;
        }

        throw new AiNoConnectionException('No OpenRouter account connected.');
    }

    /** Same two sources, no throw, no last_used_at write: this is a PROBE, not a use. */
    public function exists(string $userKey): bool
    {
        return AiConnection::activeFor($userKey) !== null || (string) config('services.openrouter.key') !== '';
    }
}
