<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\EquipmentController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\RecoveryPackController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WasteController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    // Menu & POS Orders
    Route::get('/menu-items', [MenuController::class, 'index']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders/sync', [OrderController::class, 'sync']);
    Route::patch('/orders/{order}/void', [OrderController::class, 'void']);

    // Stock Closing Counts, Movements & Waste
    Route::get('/stock-items', [StockController::class, 'index']);
    Route::get('/stock-movements', [StockController::class, 'movements']);
    Route::patch('/stock-items/{stockItem}/count', [StockController::class, 'updateCount']);
    Route::get('/waste-logs', [WasteController::class, 'index']);
    Route::post('/waste-logs', [WasteController::class, 'store']);

    // Daily Reports & Discount Rates
    Route::get('/reports/daily', [ReportController::class, 'daily']);
    Route::get('/settings/discounts', [ReportController::class, 'getDiscounts']);

    // Owner-Only Routes
    Route::middleware('owner')->group(function () {
        Route::post('/menu-items', [MenuController::class, 'store']);
        Route::put('/menu-items/{menuItem}', [MenuController::class, 'update']);
        Route::put('/menu-items/{menuItem}/recipe', [MenuController::class, 'syncRecipe']);
        Route::patch('/menu-items/{menuItem}/toggle', [MenuController::class, 'toggle']);

        Route::post('/stock-items', [StockController::class, 'store']);
        Route::post('/stock-items/{stockItem}/stock-in', [StockController::class, 'stockIn']);
        Route::patch('/stock-items/{stockItem}/expiry', [StockController::class, 'updateExpiry']);

        Route::get('/reports/analytics', [ReportController::class, 'analytics']);
        Route::get('/audit-logs', [ReportController::class, 'auditLogs']);

        Route::get('/equipment', [EquipmentController::class, 'index']);
        Route::post('/equipment', [EquipmentController::class, 'store']);

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::patch('/users/{user}/toggle-active', [UserController::class, 'toggleActive']);
        Route::patch('/users/{user}/reset-password', [UserController::class, 'resetPassword']);

        Route::get('/backups', [BackupController::class, 'index']);
        Route::post('/backups', [BackupController::class, 'store']);
        Route::post('/backups/{backup}/restore', [BackupController::class, 'restore']);

        Route::get('/recovery-packs', [RecoveryPackController::class, 'index']);
        Route::get('/recovery-packs/estimate', [RecoveryPackController::class, 'estimate']);
        Route::post('/recovery-packs/generate', [RecoveryPackController::class, 'generate']);
        Route::post('/recovery-packs/{recoveryPack}/retry-email', [RecoveryPackController::class, 'retryEmail']);
        Route::get('/recovery-packs/{recoveryPack}/download', [RecoveryPackController::class, 'download']);
        Route::get('/settings/recovery', [RecoveryPackController::class, 'getSettings']);
        Route::put('/settings/recovery', [RecoveryPackController::class, 'updateSettings']);
        Route::put('/settings/discounts', [ReportController::class, 'updateDiscounts']);
    });
});