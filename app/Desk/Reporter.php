<?php

declare(strict_types=1);

namespace App\Desk;

use App\Models\DeskEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Every agent speaks through here: rows in desk_events (dashboard) and,
 * when configured, Telegram. "Report to Telegram, never to a dashboard nobody opens."
 */
class Reporter
{
    public ?int $runId = null;

    public function info(string $agent, string $message, array $payload = []): DeskEvent
    {
        return $this->event('info', $agent, $message, $payload);
    }

    public function warn(string $agent, string $message, array $payload = []): DeskEvent
    {
        return $this->event('warn', $agent, $message, $payload);
    }

    public function error(string $agent, string $message, array $payload = []): DeskEvent
    {
        $this->telegram("⚠️ {$agent}: {$message}");

        return $this->event('error', $agent, $message, $payload);
    }

    public function trade(string $agent, string $message, array $payload = []): DeskEvent
    {
        $this->telegram("💹 {$agent}: {$message}");

        return $this->event('trade', $agent, $message, $payload);
    }

    public function event(string $level, string $agent, string $message, array $payload = []): DeskEvent
    {
        $logLevel = match ($level) {
            'warn' => 'warning',
            'error' => 'error',
            default => 'info',
        };
        Log::channel('desk')->{$logLevel}("[{$agent}] {$message}", $payload);

        return DeskEvent::create([
            'level' => $level,
            'agent' => mb_substr($agent, 0, 8),   // desk_events.agent is VARCHAR(8); sqlite tests never enforce it, MariaDB rejects the row
            'message' => mb_substr($message, 0, 500),
            'payload' => $payload ?: null,
            'desk_run_id' => $this->runId,
        ]);
    }

    public function telegram(string $text): bool
    {
        $token = config('desk.telegram.bot_token');
        $chat = config('desk.telegram.chat_id');
        if (! $token || ! $chat) {
            return false;
        }
        try {
            return Http::timeout(8)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chat,
                'text' => mb_substr($text, 0, 4000),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ])->ok();
        } catch (\Throwable $e) {
            Log::warning('telegram failed: '.$e->getMessage());

            return false;
        }
    }
}
