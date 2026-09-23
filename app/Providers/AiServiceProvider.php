<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\Contracts\ChatClient;
use App\Ai\Contracts\DecisionClient;
use App\Ai\OpenRouterChatClient;
use App\Ai\OpenRouterDecisionClient;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ChatClient::class, OpenRouterChatClient::class);
        $this->app->bind(DecisionClient::class, OpenRouterDecisionClient::class);
    }
}
