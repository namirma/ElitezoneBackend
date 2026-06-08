<?php

use App\Http\Controllers\Api\Admin\AdminActivityLogController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminFinancialController;
use App\Http\Controllers\Api\Admin\AdminOrderController;
use App\Http\Controllers\Api\Admin\AdminSearchController;
use App\Http\Controllers\Api\Admin\AdminSupplierController;
use App\Http\Controllers\Api\Admin\AdminTicketController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AdminWalletController;
use App\Http\Controllers\Api\Admin\OrderWeighbridgeController;
use App\Http\Controllers\Api\DebugController;
use App\Http\Controllers\Api\Podium\IdentityInquirySc2Controller;
use App\Http\Controllers\Api\Podium\MobileNidMatchController;
use App\Http\Controllers\Api\Podium\PodiumBalanceController;
use App\Http\Controllers\Api\Podium\PodiumBatchSettlementController;
use App\Http\Controllers\Api\Podium\PodiumSettlementController;
use App\Http\Controllers\Api\Podium\PodiumSettlementInquiryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin & Podium Routes
|--------------------------------------------------------------------------
| همه روتهای admin-only — هم پنل ادمین و هم سرویس‌های Podium که فقط
| ادمین حق فراخوانی دارد. middleware [auth:sanctum, role:admin] روی هر
| دو گروه اعمال می‌شود.
*/

// ── Podium services (admin-only proxy) ────────────────────────────────────
Route::prefix('v1/podium')
    ->middleware(['auth:sanctum', 'role:admin'])
    ->group(function () {
        Route::post('/settlements/single', [PodiumSettlementController::class, 'single']);
        Route::get('/balances', [PodiumBalanceController::class, 'index']);
        Route::post('/settlements/batch', [PodiumBatchSettlementController::class, 'store']);
        Route::get('/settlements/inquiry', [PodiumSettlementInquiryController::class, 'index']);
        Route::post('/identity-inquiry-sc2', IdentityInquirySc2Controller::class);
        Route::post('/mobile-nid-match', [MobileNidMatchController::class, 'store']);
    });

// ── Admin panel (auth + role:admin) ───────────────────────────────────────
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {

    // Dashboard — KPI اجرایی
    Route::get('/dashboard', [AdminDashboardController::class, 'index']);

    // Debug helpers
    Route::get('/debug/test-cache', [DebugController::class, 'testCache']);
    Route::get('/debug/kavir-products', [DebugController::class, 'kavirProducts']);

    // Activity log
    Route::get('/activities', [AdminActivityLogController::class, 'index']);

    // Search
    Route::controller(AdminSearchController::class)->prefix('search')->group(function () {
        Route::get('/users', 'searchUsers');
        Route::get('/transactions', 'searchTransactions');
    });

    // Users — back-office lookup endpoints (group 1)
    Route::prefix('users')->controller(AdminUserController::class)->group(function () {
        Route::post('/fetch-profile', 'getUserProfileForBackOffice');
        Route::get('/{user}/orders', 'getUserOrders');
        Route::get('/{user}/transactions', 'getUserTransactions');
    });

    // Financial documents + health
    Route::controller(AdminFinancialController::class)->prefix('financial')->group(function () {
        Route::get('/company/invoices', 'getCompanyInvoices');
        Route::get('/company/expert-shares', 'getExpertShares');
        Route::get('/supplier/documents', 'getSupplierDocuments');
        Route::get('/expert/documents', 'getExpertDocuments');
        // Financial health & alerts
        Route::get('/health', 'financialHealth');
        Route::get('/pending-debts', 'pendingDebts');
        Route::get('/pending-moadian', 'pendingMoadianInvoices');
    });

    // Users — CRUD (group 2)
    Route::controller(AdminUserController::class)->prefix('users')->group(function () {
        Route::get('/', 'index');
        Route::get('/{user}', 'show');
        Route::post('/{user}/toggle-status', 'toggleStatus');
        Route::post('/{user}/note', 'addNote');
        Route::post('/{user}/verify-consultant',   'verifyConsultant');
        Route::post('/{user}/unverify-consultant', 'unverifyConsultant');
    });

    // Suppliers — مدیریت سوپلایرها
    Route::controller(AdminSupplierController::class)->prefix('suppliers')->group(function () {
        Route::get('/', 'index');
        Route::get('/{user}', 'show');
        Route::put('/{user}/formula', 'updateFormula');
        Route::get('/{user}/orders', 'orders');
    });

    // Orders
    Route::controller(AdminOrderController::class)->prefix('orders')->group(function () {
        Route::get('/', 'index');
        Route::get('/{order}/history', 'history');
        Route::get('/{order}', 'show');
        Route::post('/{order}/status', 'changeStatus');
        // Doc P — فاکتور مودیان سیستم (سازه → خریدار)، آزادسازی WVAT hold
        Route::post('/{order}/system-moadian-invoice', 'submitSystemMoadianInvoice');
    });

    // Tickets — مدیریت تیکت‌های پشتیبانی
    Route::controller(AdminTicketController::class)->prefix('tickets')->group(function () {
        Route::get('/', 'index');
        Route::get('/{ticket}', 'show');
        Route::post('/{ticket}/reply', 'reply');
        Route::post('/{ticket}/status', 'changeStatus');
    });

    // Wallet
    Route::controller(AdminWalletController::class)->prefix('wallet')->group(function () {
        Route::get('/transactions', 'index');
        Route::post('/transactions/{transaction}/status', 'changeStatus');
    });

    // Weighbridge (note: at /admin root, not nested under /orders)
    Route::post('/{order}/weighbridge', [OrderWeighbridgeController::class, 'submitWeight'])
        ->name('admin.orders.weighbridge.submit');
});
