<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Ai\Tools\ToolRegistry::class, fn () => \App\Ai\Tools\ToolRegistry::withDefaults());
    }

    public function boot(): void
    {
        // The private network supplies the desk's access boundary.
    }
}
