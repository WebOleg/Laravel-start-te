<?php

/**
 * API routes for Tether application.
 */

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DebtorController as AdminDebtorController;
use App\Http\Controllers\Admin\VopLogController as AdminVopLogController;
use App\Http\Controllers\Admin\BillingAttemptController as AdminBillingAttemptController;
use App\Http\Controllers\Admin\UploadController as AdminUploadController;

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::apiResource('clients', ClientController::class);
    Route::apiResource('uploads', UploadController::class);

    // Admin routes
    Route::prefix('admin')->group(function () {
        Route::get('dashboard', [AdminDashboardController::class, 'index']);
        Route::get('uploads/{upload}/status', [AdminUploadController::class, 'status']);
        Route::apiResource('uploads', AdminUploadController::class)->only(['index', 'show', 'store']);
        Route::apiResource('debtors', AdminDebtorController::class)->only(['index', 'show']);
        Route::apiResource('vop-logs', AdminVopLogController::class)->only(['index', 'show']);
        Route::apiResource('billing-attempts', AdminBillingAttemptController::class)->only(['index', 'show']);
    });
});
