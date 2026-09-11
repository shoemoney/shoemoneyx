<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Owner of an AI call. No authentication is wired yet — the web gate only sets a
 * session boolean — so every call belongs to one local desk operator. Phase 4
 * swaps id() for the authenticated user; callers only ever persist key(), so the
 * schema does not move with it.
 */
final class CurrentUser
{
    public static function id(): int|string
    {
        return auth()->id() ?? 'local';
    }

    public static function key(): string
    {
        return (string) self::id();
    }
}
