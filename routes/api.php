<?php

use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\StockLevelController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function () {
    Route::post('auth/tokens', [AuthTokenController::class, 'store'])
        ->middleware('throttle:login')
        ->name('auth.tokens.store');

    Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
        Route::delete('auth/tokens/current', [AuthTokenController::class, 'destroy'])->name('auth.tokens.destroy');
        Route::get('auth/me', [AuthTokenController::class, 'me'])->name('auth.me');

        Route::apiResource('users', UserController::class)->except('destroy');

        // Catalogue records are referenced by stock and orders, so they are
        // deactivated rather than deleted.
        Route::apiResource('warehouses', WarehouseController::class)->except('destroy');
        Route::apiResource('products', ProductController::class)->except('destroy');
        Route::apiResource('customers', CustomerController::class)->except('destroy');

        Route::prefix('warehouses/{warehouse}')->name('warehouses.')->group(function () {
            Route::get('stock', [StockLevelController::class, 'index'])->name('stock.index');
            Route::patch('stock/{product}', [StockLevelController::class, 'updateReorderPoint'])->name('stock.update');
            Route::get('stock-movements', [StockMovementController::class, 'index'])->name('stock-movements.index');
            Route::post('stock-receipts', [StockMovementController::class, 'receive'])->name('stock-receipts.store');
            Route::post('stock-adjustments', [StockMovementController::class, 'adjust'])->name('stock-adjustments.store');
        });
        Route::get('products/{product}/stock', [StockLevelController::class, 'forProduct'])->name('products.stock');
    });
});
