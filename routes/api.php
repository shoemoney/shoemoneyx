<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\ArenaController;
use App\Http\Controllers\Api\ArenaSeatController;
use App\Http\Controllers\Api\BacktestController;
use App\Http\Controllers\Api\DeskController;
use App\Http\Controllers\Api\ExchangeController;
use App\Http\Controllers\Api\FarmController;
use App\Http\Controllers\Api\HubAccountController;
use App\Http\Controllers\Api\HubArchiveController;
use App\Http\Controllers\Api\HubContestController;
use App\Http\Controllers\Api\LandingController;
use App\Http\Controllers\Api\OptimizerController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\RunController;
use App\Http\Controllers\Api\SmxController;
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Api\StrategyPluginController;
use App\Http\Controllers\Api\StrategyPluginVersionController;
use App\Http\Controllers\Api\StrategySyncController;
use App\Http\Controllers\Api\UdfController;
use App\Http\Middleware\DeskMutationThrottle;
use App\Http\Middleware\DeskToken;
use Illuminate\Support\Facades\Route;

// The desk is operated inside a private, firewalled network. DeskToken adds an
// optional master-password layer on top of that access boundary (no-op when unset).
Route::middleware(DeskToken::class)->group(function () {
    Route::get('/landing', [LandingController::class, 'index']);
    Route::get('/status', [StatusController::class, 'index']);
    Route::get('/exchanges', [ExchangeController::class, 'index']);
    Route::get('/bank/history', [StatusController::class, 'bankHistory']);
    Route::get('/positions', [PositionController::class, 'index']);
    Route::get('/runs/latest', [RunController::class, 'latest']);
    Route::get('/events', [RunController::class, 'events']);

    Route::get('/positions/{position}', [PositionController::class, 'show']);
    Route::post('/positions/{position}/close', [PositionController::class, 'close']);

    Route::get('/runs', [RunController::class, 'index']);
    Route::get('/runs/{run}', [RunController::class, 'show']);
    Route::get('/candidates', [RunController::class, 'candidates']);
    Route::get('/fills', [RunController::class, 'fills']);

    Route::post('/desk/{action}', [DeskController::class, 'action'])->middleware(DeskMutationThrottle::class);
    Route::get('/settings', [DeskController::class, 'settings']);
    Route::put('/settings', [DeskController::class, 'updateSetting']);
    Route::delete('/settings/{key}', [DeskController::class, 'deleteSetting'])->where('key', '.*');
    Route::get('/products', [DeskController::class, 'products']);
    Route::get('/quote', [DeskController::class, 'quote']);
    Route::get('/report', [DeskController::class, 'report']);
    Route::get('/smx', [SmxController::class, 'show']);

    Route::get('/backtests', [BacktestController::class, 'index']);
    Route::post('/backtests', [BacktestController::class, 'store']);
    Route::get('/backtests/{backtest}', [BacktestController::class, 'show']);
    Route::get('/optimizer/champions', [OptimizerController::class, 'champions']);
    Route::get('/optimizer/rounds', [OptimizerController::class, 'rounds']);
    Route::get('/optimizer/candidates', [OptimizerController::class, 'candidates']);
    Route::get('/farm', [FarmController::class, 'show']);

    // Live arena: split-test challengers against the champion, each in its own paper account.
    Route::get('/arena', [ArenaSeatController::class, 'index']);
    Route::post('/arena/seats', [ArenaSeatController::class, 'store']);
    Route::post('/arena/seats/{seat}/promote', [ArenaSeatController::class, 'promote']);
    Route::post('/arena/seats/{seat}/retire', [ArenaSeatController::class, 'retire']);
    Route::delete('/arena/seats/{seat}', [ArenaSeatController::class, 'destroy']);
    // The old optimizer champion-vs-backtested-challengers view — unchanged, just moved.
    Route::get('/arena/optimizer', [ArenaController::class, 'show']);

    Route::get('/ai/status', [AiController::class, 'status']);
    Route::get('/ai/models', [AiController::class, 'models']);
    Route::get('/ai/usage', [AiController::class, 'usage']);
    Route::post('/ai/disconnect', [AiController::class, 'disconnect']);

    Route::get('/strategy-models', [StrategyPluginController::class, 'models']);
    Route::get('/strategy-plugins', [StrategyPluginController::class, 'index']);
    Route::post('/strategy-plugins/validate', [StrategyPluginController::class, 'validate']);
    Route::post('/strategy-plugins', [StrategyPluginController::class, 'store']);
    Route::get('/strategy-plugins/{plugin}', [StrategyPluginController::class, 'show']);
    Route::get('/strategy-plugins/{plugin}/export/{format}', [StrategyPluginController::class, 'export']);
    Route::post('/strategy-plugins/{plugin}/backtest', [StrategyPluginController::class, 'backtest']);
    Route::post('/strategy-plugins/{plugin}/auto-backtest', [StrategyPluginController::class, 'toggleAutoBacktest']);
    Route::get('/strategy-plugins/{plugin}/reviews', [StrategyPluginController::class, 'reviews']);
    Route::post('/strategy-plugins/{plugin}/publish', [StrategyPluginController::class, 'publish']);

    Route::get('/strategy-plugins/{plugin}/versions', [StrategyPluginVersionController::class, 'index']);
    Route::get('/strategy-plugins/{plugin}/versions/{a}/diff/{b}', [StrategyPluginVersionController::class, 'diff'])
        ->where(['a' => '\d+\.\d+\.\d+', 'b' => '\d+\.\d+\.\d+']);
    Route::get('/strategy-plugins/{plugin}/versions/{version}', [StrategyPluginVersionController::class, 'show'])
        ->where('version', '\d+\.\d+\.\d+');
    Route::post('/strategy-plugins/{plugin}/versions/{version}/restore', [StrategyPluginVersionController::class, 'restore'])
        ->where('version', '\d+\.\d+\.\d+');
    Route::post('/strategy-assist', [StrategyPluginController::class, 'assist'])->middleware('throttle:30,1');

    Route::get('/strategies/sync/status', [StrategySyncController::class, 'status']);
    Route::post('/strategies/sync/check', [StrategySyncController::class, 'check']);
    Route::post('/strategies/sync/import', [StrategySyncController::class, 'import']);
    Route::post('/strategies/sync/import-all', [StrategySyncController::class, 'importAll']);

    Route::post('/agent/conversations', [AgentController::class, 'store']);
    Route::post('/agent/conversations/{conversation}/turn', [AgentController::class, 'turn']);
    Route::get('/agent/conversations/{conversation}', [AgentController::class, 'show']);

    // Community hub (see docs/HUB_API.md) — account, archive browse/import, contests.
    Route::post('/hub/register', [HubAccountController::class, 'register']);
    Route::post('/hub/login', [HubAccountController::class, 'login']);
    Route::post('/hub/link', [HubAccountController::class, 'link']);
    Route::get('/hub/status', [HubAccountController::class, 'status']);
    Route::post('/hub/disconnect', [HubAccountController::class, 'disconnect']);

    Route::get('/hub/strategies/search', [HubArchiveController::class, 'search']);
    Route::get('/hub/strategies/typeahead', [HubArchiveController::class, 'typeahead']);
    Route::get('/hub/strategies/{slug}/versions/{version}', [HubArchiveController::class, 'version']);
    Route::get('/hub/strategies/{slug}/comments', [HubArchiveController::class, 'comments']);
    Route::get('/hub/strategies/{slug}', [HubArchiveController::class, 'show']);
    Route::post('/hub/import', [HubArchiveController::class, 'import']);

    Route::get('/hub/contests', [HubContestController::class, 'index']);
    Route::get('/hub/contests/{slug}', [HubContestController::class, 'show']);
    Route::post('/hub/contests/{slug}/enter', [HubContestController::class, 'enter']);
    Route::delete('/hub/contests/{slug}/enter', [HubContestController::class, 'withdraw']);

    // TradingView UDF datafeed
    Route::prefix('udf')->group(function () {
        Route::get('/config', [UdfController::class, 'config']);
        Route::get('/time', [UdfController::class, 'time']);
        Route::get('/search', [UdfController::class, 'search']);
        Route::get('/symbols', [UdfController::class, 'symbols']);
        Route::get('/history', [UdfController::class, 'history']);
        Route::get('/marks', [UdfController::class, 'marks']);
    });
});
