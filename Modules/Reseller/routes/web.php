<?php

use Illuminate\Support\Facades\Route;
use Modules\Reseller\Http\Controllers\DashboardController;
use Modules\Reseller\Http\Controllers\PlanPurchaseController;
use Modules\Reseller\Http\Controllers\ConfigController;
use Modules\Reseller\Http\Controllers\SyncController;
use Modules\Reseller\Http\Controllers\TicketController;

/*
|--------------------------------------------------------------------------
| Reseller Routes
|--------------------------------------------------------------------------
|
| All routes for the reseller panel
|
*/

Route::prefix('reseller')
    ->middleware(['web', 'auth', 'reseller', 'wallet.access'])
    ->name('reseller.')
    ->group(function () {
        // Dashboard
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        // Plan-based resellers
        Route::get('/plans', [PlanPurchaseController::class, 'index'])->name('plans.index');
        Route::post('/bulk', [PlanPurchaseController::class, 'store'])
            ->middleware('throttle:20,1')  // 20 requests per minute
            ->name('bulk.store');
        Route::get('/orders/{order}', [PlanPurchaseController::class, 'show'])->name('orders.show');

        // Traffic-based resellers
        Route::get('/configs', [ConfigController::class, 'index'])->name('configs.index');
        Route::get('/configs/create', [ConfigController::class, 'create'])->name('configs.create');
        Route::post('/configs', [ConfigController::class, 'store'])
            ->middleware('throttle:10,1')  // 10 requests per minute
            ->name('configs.store');
        Route::get('/configs/{config}/edit', [ConfigController::class, 'edit'])->name('configs.edit');
        Route::put('/configs/{config}', [ConfigController::class, 'update'])->name('configs.update');
        Route::post('/configs/{config}/reset-usage', [ConfigController::class, 'resetUsage'])->name('configs.resetUsage');
        Route::post('/configs/{config}/disable', [ConfigController::class, 'disable'])->name('configs.disable');
        Route::post('/configs/{config}/enable', [ConfigController::class, 'enable'])->name('configs.enable');
        Route::delete('/configs/{config}', [ConfigController::class, 'destroy'])->name('configs.destroy');

        // Manual sync
        Route::post('/sync', [SyncController::class, 'sync'])->name('sync');

        // Tickets
        Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
        Route::get('/tickets/create', [TicketController::class, 'create'])->name('tickets.create');
        Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
        Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
        Route::post('/tickets/{ticket}/reply', [TicketController::class, 'reply'])->name('tickets.reply');
    });
