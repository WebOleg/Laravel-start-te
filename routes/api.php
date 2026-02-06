<?php

use App\Http\Controllers\Admin\DescriptorController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\EmpAccountController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DebtorController as AdminDebtorController;
use App\Http\Controllers\Admin\VopLogController as AdminVopLogController;
use App\Http\Controllers\Admin\BillingAttemptController as AdminBillingAttemptController;
use App\Http\Controllers\Admin\BillingController as AdminBillingController;
use App\Http\Controllers\Admin\ChargebackController;
use App\Http\Controllers\Admin\ReconciliationController as AdminReconciliationController;
use App\Http\Controllers\Admin\UploadController as AdminUploadController;
use App\Http\Controllers\Admin\StatsController as AdminStatsController;
use App\Http\Controllers\Admin\VopController as AdminVopController;
use App\Http\Controllers\Admin\EmpRefreshController as AdminEmpRefreshController;
use App\Http\Controllers\Admin\BicAnalyticsController as AdminBicAnalyticsController;
use App\Http\Middleware\EmpWebhookSecurity;
use App\Http\Controllers\Webhook\EmpWebhookController;

Route::post('/login', [AuthController::class, 'login'])->name('login');

Route::prefix('auth')->group(function () {
    Route::post('/setup-2fa', [AuthController::class, 'setup2fa']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
    Route::post('/verify-backup-code', [AuthController::class, 'verifyBackupCode']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    Route::prefix('admin')->group(function () {
        Route::get('dashboard', [AdminDashboardController::class, 'index']);

        Route::get('stats/chargeback-rates', [AdminStatsController::class, 'chargebackRates']);
        Route::get('stats/chargeback-codes', [AdminStatsController::class, 'chargebackCodes']);
        Route::get('stats/chargeback-banks', [AdminStatsController::class, 'chargebackBanks']);

        Route::prefix('analytics/bic')->group(function () {
            Route::get('/', [AdminBicAnalyticsController::class, 'index']);
            Route::get('/export', [AdminBicAnalyticsController::class, 'export']);
            Route::post('/clear-cache', [AdminBicAnalyticsController::class, 'clearCache']);
            Route::get('/{bic}', [AdminBicAnalyticsController::class, 'show']);
        });

        Route::get('uploads/{upload}/status', [AdminUploadController::class, 'status']);
        Route::get('uploads/{upload}/debtors', [AdminUploadController::class, 'debtors']);
        Route::post('uploads/{upload}/validate', [AdminUploadController::class, 'validate']);
        Route::get('uploads/{upload}/validation-stats', [AdminUploadController::class, 'validationStats']);
        Route::post('uploads/{upload}/filter-chargebacks', [AdminUploadController::class, 'filterChargebacks']);
        Route::apiResource('uploads', AdminUploadController::class)->only(['index', 'show', 'store', 'destroy']);

        Route::get('uploads/{upload}/vop-stats', [AdminVopController::class, 'stats']);
        Route::post('uploads/{upload}/verify-vop', [AdminVopController::class, 'verify']);
        Route::get('uploads/{upload}/vop-logs', [AdminVopController::class, 'logs']);
        Route::post('vop/verify-single', [AdminVopController::class, 'verifySingle']);

        Route::post('uploads/{upload}/sync', [AdminBillingController::class, 'sync']);
        Route::get('uploads/{upload}/billing-stats', [AdminBillingController::class, 'stats']);
        Route::post('billing-attempts/{billing_attempt}/retry', [AdminBillingAttemptController::class, 'retry']);

        Route::post('billing-attempts/{billing_attempt}/reconcile', [AdminReconciliationController::class, 'reconcileAttempt']);
        Route::post('uploads/{upload}/reconcile', [AdminReconciliationController::class, 'reconcileUpload']);
        Route::get('uploads/{upload}/reconciliation-stats', [AdminReconciliationController::class, 'uploadStats']);
        Route::get('reconciliation/stats', [AdminReconciliationController::class, 'stats']);
        Route::post('reconciliation/bulk', [AdminReconciliationController::class, 'bulk']);

        Route::prefix('emp')->group(function () {
            Route::post('/refresh', [AdminEmpRefreshController::class, 'refresh']);
            Route::get('/refresh/status', [AdminEmpRefreshController::class, 'currentStatus']);
            Route::get('/refresh/{jobId}', [AdminEmpRefreshController::class, 'status']);

            Route::get('/accounts', [EmpAccountController::class, 'index']);
            Route::get('/accounts/active', [EmpAccountController::class, 'active']);
            Route::post('/accounts/{empAccount}/activate', [EmpAccountController::class, 'setActive']);
            Route::get('/accounts/{empAccount}/stats', [EmpAccountController::class, 'stats']);
        });

        Route::prefix('chargebacks')->group(function () {
            Route::get('', [ChargebackController::class, 'index']);
            Route::get('/codes', [ChargebackController::class, 'codes']);
        });

        Route::post('debtors/{debtor}/validate', [AdminDebtorController::class, 'validate']);
        Route::apiResource('debtors', AdminDebtorController::class)->only(['index', 'show', 'update', 'destroy']);
        Route::apiResource('vop-logs', AdminVopLogController::class)->only(['index', 'show']);
        Route::apiResource('billing-attempts', AdminBillingAttemptController::class)->only(['index', 'show']);

        Route::apiResource('billing/descriptors', DescriptorController::class);
    });
});

Route::prefix('webhooks')->group(function () {
    Route::post('/emp/{token}', [EmpWebhookController::class, 'handle'])
        ->middleware(EmpWebhookSecurity::class)
        ->name('webhooks.emp');
});
