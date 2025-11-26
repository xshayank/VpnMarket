<?php

use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\PanelController;
use App\Http\Controllers\AuditLogsController;
use App\Http\Controllers\PanelsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Admin-only routes for panel management
Route::middleware(['auth', 'admin'])->prefix('admin')->group(function () {
    Route::apiResource('panels', PanelsController::class);
    Route::post('panels/{panel}/test-connection', [PanelsController::class, 'testConnection']);
    Route::get('audit-logs', [AuditLogsController::class, 'index']);
});

// API key management routes (session-authenticated, for resellers)
Route::middleware(['auth'])->prefix('keys')->group(function () {
    Route::get('/', [ApiKeyController::class, 'index']);
    Route::post('/', [ApiKeyController::class, 'store']);
    Route::put('/{id}', [ApiKeyController::class, 'update']);
    Route::post('/{id}/revoke', [ApiKeyController::class, 'revoke']);
    Route::post('/{id}/rotate', [ApiKeyController::class, 'rotate']);
    Route::delete('/{id}', [ApiKeyController::class, 'destroy']);
});

// Reseller API routes (API key authenticated)
Route::middleware(['api.key'])->prefix('v1')->group(function () {
    // Panels - list available panels
    Route::get('panels', [PanelController::class, 'index'])
        ->middleware('api.key:panels:list');

    // Configs - CRUD operations
    Route::get('configs', [ConfigController::class, 'index'])
        ->middleware('api.key:configs:read');
    Route::get('configs/{name}', [ConfigController::class, 'show'])
        ->middleware('api.key:configs:read');
    Route::post('configs', [ConfigController::class, 'store'])
        ->middleware('api.key:configs:create');
    Route::put('configs/{name}', [ConfigController::class, 'update'])
        ->middleware('api.key:configs:update');
    Route::delete('configs/{name}', [ConfigController::class, 'destroy'])
        ->middleware('api.key:configs:delete');
});
