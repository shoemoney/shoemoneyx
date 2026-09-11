<?php

use App\Desk\Onboarding\OnboardingWizard;
use App\Http\Controllers\AiConnectController;
use App\Http\Controllers\DeskAuthController;
use App\Http\Controllers\SiteDiscoveryController;
use App\Http\Middleware\MasterPassword;
use Illuminate\Support\Facades\Route;

Route::get('/robots.txt', [SiteDiscoveryController::class, 'robots']);
Route::get('/sitemap.xml', [SiteDiscoveryController::class, 'sitemap']);
Route::get('/llms.txt', [SiteDiscoveryController::class, 'llms']);
Route::get('/llms-full.txt', [SiteDiscoveryController::class, 'full']);
Route::get('/ai.txt', [SiteDiscoveryController::class, 'ai']);
Route::get('/.well-known/ai.txt', [SiteDiscoveryController::class, 'ai']);
Route::get('/{page}.md', [SiteDiscoveryController::class, 'markdown'])->where('page', '[a-z-]+');

// Master-password gate: login first, then the Vue SPA.
Route::get('/login', [DeskAuthController::class, 'show']);
Route::post('/login', [DeskAuthController::class, 'store'])->middleware('throttle:10,1');
Route::post('/logout', [DeskAuthController::class, 'destroy']);

// OpenRouter OAuth PKCE — full-page redirects, so they sit on the web gate, not the API one.
Route::get('/ai/openrouter/connect', [AiConnectController::class, 'connect'])->middleware(MasterPassword::class);
Route::get('/ai/openrouter/callback', [AiConnectController::class, 'callback'])->middleware(MasterPassword::class);

// First-run wizard: unfinished onboarding takes over the root before the SPA shell does.
// The public demo never touches the database (see PublicDemo middleware); a DB failure here
// fails open to the app shell rather than 500ing, same as Settings::masterPassword().
Route::get('/', function () {
    try {
        $complete = config('site.demo') || app(OnboardingWizard::class)->isComplete();
    } catch (Throwable) {
        $complete = true;
    }

    return $complete ? view('app') : redirect('/onboarding');
})->middleware(MasterPassword::class);

// Vue SPA — every non-API path renders the app shell.
Route::view('/{any?}', 'app')->where('any', '^(?!api).*$')->middleware(MasterPassword::class);
