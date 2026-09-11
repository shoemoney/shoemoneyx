<?php

declare(strict_types=1);

namespace App\Providers;

use App\Exchange\Contracts\Exchange;
use App\Exchange\Contracts\MarketData;
use App\Exchange\ExchangeRegistry;
use Illuminate\Support\ServiceProvider;

class ExchangeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ExchangeRegistry::class);

        $this->app->bind(Exchange::class, fn ($app) => $app->make(ExchangeRegistry::class)->active());

        $this->app->bind(MarketData::class, fn ($app) => $app->make(Exchange::class)->marketData());
    }
}
