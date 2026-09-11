<?php

declare(strict_types=1);

namespace App\Desk;

use App\Desk\Contracts\Strategy;

class StrategyRegistry
{
    /** @return array<string, class-string<Strategy>> */
    public function all(): array
    {
        return config('desk.strategies', []);
    }

    public function make(?string $key = null): Strategy
    {
        $key ??= app(Settings::class)->strategyKey();
        $class = $this->all()[$key] ?? throw new \InvalidArgumentException("Unknown strategy '{$key}'. Register it in config/desk.php.");

        return app($class);
    }
}
