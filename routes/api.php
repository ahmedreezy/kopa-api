<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BorrowerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\OperationsController;
use App\Http\Controllers\Api\RepaymentController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'Kopa API is connected.',
    ]);
});

Route::post('/auth/register-company', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('tenant')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/dashboard', DashboardController::class);
    Route::get('/search/{query}', [DashboardController::class, 'search']);
    Route::apiResource('borrowers', BorrowerController::class)->only(['index', 'store', 'show']);
    Route::post('/loans/calculate', [LoanController::class, 'calculate']);
    Route::apiResource('loans', LoanController::class)->only(['index', 'store', 'show']);
    Route::post('/loans/{loan}/repayments', [RepaymentController::class, 'store']);
    Route::post('/repayments/{repayment}/reverse', [RepaymentController::class, 'reverse']);
    Route::get('/receipts/{receipt}', [RepaymentController::class, 'receipt']);
    Route::get('/collections', [OperationsController::class, 'collections']);
    Route::get('/reports/summary', [OperationsController::class, 'reportSummary']);
    Route::get('/company', [OperationsController::class, 'company']);
    Route::put('/company', [OperationsController::class, 'updateCompany']);
    Route::get('/branches', [OperationsController::class, 'branches']);
    Route::post('/branches', [OperationsController::class, 'storeBranch']);
    Route::get('/staff', [OperationsController::class, 'staff']);
    Route::post('/staff', [OperationsController::class, 'storeStaff']);
    Route::get('/audit-activity', [OperationsController::class, 'audit']);
});
