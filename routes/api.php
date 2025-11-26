<?php

use App\Http\Controllers\Api\ApiDocumentationController;
use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\MarzneshinStyleController;
use App\Http\Controllers\Api\PanelController;
use App\Http\Controllers\Api\WebhookController;
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
    Route::get('/{id}/analytics', [ApiKeyController::class, 'analytics']);
    Route::delete('/{id}', [ApiKeyController::class, 'destroy']);
    Route::get('/panels', [ApiKeyController::class, 'availablePanels']);
});

// Webhook management routes (session-authenticated, for resellers)
Route::middleware(['auth'])->prefix('webhooks')->group(function () {
    Route::get('/', [WebhookController::class, 'index']);
    Route::post('/', [WebhookController::class, 'store']);
    Route::get('/events', [WebhookController::class, 'events']);
    Route::get('/{id}', [WebhookController::class, 'show']);
    Route::put('/{id}', [WebhookController::class, 'update']);
    Route::delete('/{id}', [WebhookController::class, 'destroy']);
    Route::post('/{id}/regenerate-secret', [WebhookController::class, 'regenerateSecret']);
    Route::post('/{id}/test', [WebhookController::class, 'test']);
});

/*
|--------------------------------------------------------------------------
| API Documentation Routes (public and authenticated)
|--------------------------------------------------------------------------
*/
// Public documentation endpoints
Route::prefix('docs')->group(function () {
    Route::get('/styles', [ApiDocumentationController::class, 'styles']);
    Route::get('/documentation', [ApiDocumentationController::class, 'documentation']);
    Route::get('/cheat-sheet', [ApiDocumentationController::class, 'cheatSheet']);
    Route::get('/scopes', [ApiDocumentationController::class, 'scopes']);
    Route::get('/field-mapping', [ApiDocumentationController::class, 'fieldMapping']);
    Route::get('/webhook-events', [ApiDocumentationController::class, 'webhookEvents']);
    Route::get('/openapi', [ApiDocumentationController::class, 'openApiSpec']);
    Route::get('/markdown', [ApiDocumentationController::class, 'markdownDoc']);
});

// Authenticated documentation/analytics endpoints
Route::middleware(['auth'])->prefix('docs')->group(function () {
    Route::get('/panel-health', [ApiDocumentationController::class, 'panelHealth']);
    Route::post('/panel-health/{panelId}/refresh', [ApiDocumentationController::class, 'refreshPanelHealth']);
    Route::get('/analytics', [ApiDocumentationController::class, 'analytics']);
});

/*
|--------------------------------------------------------------------------
| Falco (Native) Style API Routes - /api/v1/...
|--------------------------------------------------------------------------
*/
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

/*
|--------------------------------------------------------------------------
| Marzneshin-Compatible Style API Routes
|--------------------------------------------------------------------------
| These routes mimic Marzneshin's API format for drop-in compatibility
| with existing Marzneshin clients and tools.
|--------------------------------------------------------------------------
*/

// Token endpoint (public - uses form auth like Marzneshin)
Route::post('admins/token', [MarzneshinStyleController::class, 'token']);

// Marzneshin-style routes (API key authenticated with specific scopes)
// Services endpoint (maps Eylandoo nodes to Marzneshin services)
Route::get('services', [MarzneshinStyleController::class, 'services'])
    ->middleware('api.key:services:list');

// Users endpoint (Marzneshin-style user management)
Route::get('users', [MarzneshinStyleController::class, 'users'])
    ->middleware('api.key:users:read');
Route::get('users/{username}', [MarzneshinStyleController::class, 'getUser'])
    ->middleware('api.key:users:read');
Route::post('users', [MarzneshinStyleController::class, 'createUser'])
    ->middleware('api.key:users:create');
Route::put('users/{username}', [MarzneshinStyleController::class, 'updateUser'])
    ->middleware('api.key:users:update');
Route::delete('users/{username}', [MarzneshinStyleController::class, 'deleteUser'])
    ->middleware('api.key:users:delete');

// User actions
Route::get('users/{username}/subscription', [MarzneshinStyleController::class, 'getUserSubscription'])
    ->middleware('api.key:subscription:read');
Route::post('users/{username}/enable', [MarzneshinStyleController::class, 'enableUser'])
    ->middleware('api.key:users:update');
Route::post('users/{username}/disable', [MarzneshinStyleController::class, 'disableUser'])
    ->middleware('api.key:users:update');
Route::post('users/{username}/reset', [MarzneshinStyleController::class, 'resetUser'])
    ->middleware('api.key:users:update');

// Nodes endpoint
Route::get('nodes', [MarzneshinStyleController::class, 'nodes'])
    ->middleware('api.key:nodes:list');
