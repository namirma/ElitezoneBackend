<?php

use App\Http\Controllers\Api\Admin\AdminActivityLogController;
use App\Http\Controllers\Api\Admin\AdminAttributeController;
use App\Http\Controllers\Api\Admin\AdminAttributeTypeController;
use App\Http\Controllers\Api\Admin\AdminAttributeValueController;
use App\Http\Controllers\Api\Admin\AdminBadgeController;
use App\Http\Controllers\Api\Admin\AdminCustomerTypeController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminEduixController;
use App\Http\Controllers\Api\Admin\AdminFactoryController;
use App\Http\Controllers\Api\Admin\AdminFactoryLocationController;
use App\Http\Controllers\Api\Admin\AdminFinancialController;
use App\Http\Controllers\Api\Admin\AdminJobExperienceController;
use App\Http\Controllers\Api\Admin\AdminOrderController;
use App\Http\Controllers\Api\Admin\AdminPodiumDepositController;
use App\Http\Controllers\Api\Admin\AdminPriceAdjustmentController;
use App\Http\Controllers\Api\Admin\AdminPriceFilterCategoryController;
use App\Http\Controllers\Api\Admin\AdminPriceFilterController;
use App\Http\Controllers\Api\Admin\AdminPriceFilterTypeController;
use App\Http\Controllers\Api\Admin\AdminProductCategoryController;
use App\Http\Controllers\Api\Admin\AdminProductController;
use App\Http\Controllers\Api\Admin\AdminProductPriceController;
use App\Http\Controllers\Api\Admin\AdminProductVariantController;
use App\Http\Controllers\Api\Admin\AdminSearchController;
use App\Http\Controllers\Api\Admin\AdminShippingRateController;
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
    ->middleware(['auth:sanctum', 'role.admin'])
    ->group(function () {
        Route::post('/settlements/single', [PodiumSettlementController::class, 'single']);
        Route::get('/balances', [PodiumBalanceController::class, 'index']);
        Route::post('/settlements/batch', [PodiumBatchSettlementController::class, 'store']);
        Route::get('/settlements/inquiry', [PodiumSettlementInquiryController::class, 'index']);
        Route::post('/identity-inquiry-sc2', IdentityInquirySc2Controller::class);
        Route::post('/mobile-nid-match', [MobileNidMatchController::class, 'store']);
    });

// ── Admin panel (auth + role:admin) ───────────────────────────────────────
Route::middleware(['auth:sanctum', 'role.admin'])->prefix('admin')->group(function () {

    // Dashboard — KPI اجرایی
    Route::get('/dashboard', [AdminDashboardController::class, 'index']);

    // Debug helpers — هرگز در پروداکشن در دسترس نباشند
    if (! app()->environment('production')) {
        Route::get('/debug/test-cache', [DebugController::class, 'testCache']);
        Route::get('/debug/kavir-products', [DebugController::class, 'kavirProducts']);
    }

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

    // واریز مستقیم بانکی — سوابق شناسه واریز + polling دستی + شارژ دستی ادمین
    Route::controller(AdminPodiumDepositController::class)->prefix('financial/podium-deposits')->group(function () {
        Route::get('/', 'index');  // لیست سوابق + آمار
        Route::post('/poll', 'poll');   // اجرای دستی polling (بدون cron)
        Route::post('/{record}/credit', 'credit'); // شارژ دستی برای واریز unmatched
    });

    // Users — CRUD (group 2)
    Route::controller(AdminUserController::class)->prefix('users')->group(function () {
        Route::get('/', 'index');
        Route::get('/{user}', 'show');
        Route::post('/{user}/toggle-status', 'toggleStatus');
        Route::post('/{user}/note', 'addNote');
        Route::post('/{user}/verify-elite-yar', 'verifyEliteYAR');
        Route::post('/{user}/unverify-elite-yar', 'unverifyEliteYAR');
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
        Route::get('/kpis', 'kpis'); // باید پیش از wildcard /{ticket} باشد
        Route::get('/departments', 'departments'); // باید پیش از wildcard /{ticket} باشد
        Route::get('/{ticket}', 'show');
        Route::post('/{ticket}/reply', 'reply');
        Route::post('/{ticket}/status', 'changeStatus');
        Route::post('/{ticket}/note', 'addNote');
        Route::post('/{ticket}/assign', 'assign');               // اختصاص به کارشناس
        Route::post('/{ticket}/department', 'moveDepartment');    // انتقال دپارتمان
        Route::post('/{ticket}/priority', 'setPriority');         // اسکالیشن (اولویت)
    });

    // Wallet
    Route::controller(AdminWalletController::class)->prefix('wallet')->group(function () {
        Route::get('/transactions', 'index');
        Route::post('/transactions/{transaction}/status', 'changeStatus');
    });

    // Shipping Rates — CRUD نرخ‌های حمل‌ونقل
    Route::controller(AdminShippingRateController::class)->prefix('shipping-rates')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{shippingRate}', 'update');
        Route::delete('/{shippingRate}', 'destroy');
        Route::post('/{shippingRate}/toggle', 'toggle');
    });

    // ══════════════════════════════════════════════════════════════════════
    // Verification queues — صف‌های تأیید (اطلاعات شغلی / مدارک تحصیلی)
    // ══════════════════════════════════════════════════════════════════════

    // Job experiences — صفِ تأیید اطلاعات شغلی (لیست منتظرها + دیدن مدرک + تأیید/رد)
    Route::controller(AdminJobExperienceController::class)->prefix('job-experiences')->group(function () {
        Route::get('/', 'index');
        Route::get('/{jobExperience}', 'show');
        Route::get('/{jobExperience}/file', 'download')->name('admin.job-experiences.file');
        Route::post('/{jobExperience}/approve', 'approve');
        Route::post('/{jobExperience}/reject', 'reject');
    });

    // Education records (Eduix) — صفِ تأیید مدارک تحصیلی
    Route::controller(AdminEduixController::class)->prefix('education-records')->group(function () {
        Route::get('/', 'index');
        Route::get('/{eduixRecord}', 'show');
        Route::post('/{eduixRecord}/approve', 'approve');
        Route::post('/{eduixRecord}/reject', 'reject');
    });

    // ══════════════════════════════════════════════════════════════════════
    // Catalog management — مدیریت کاتالوگ (محصول/دسته/ویژگی/قیمت/...)
    // ══════════════════════════════════════════════════════════════════════

    // Badges — CRUD نشان‌ها (آپلود آیکون با POST/multipart)
    Route::controller(AdminBadgeController::class)->prefix('badges')->group(function () {
        Route::get('/', 'index');
        Route::get('/{badge}', 'show');
        Route::post('/', 'store');
        Route::put('/{badge}', 'update');
        Route::post('/{badge}', 'update'); // multipart file replace (PHP can't parse uploads on raw PUT)
        Route::delete('/{badge}', 'destroy');
    });

    // Product Categories — CRUD دسته‌بندی‌ها (آپلود تصویر با POST/multipart)
    Route::controller(AdminProductCategoryController::class)->prefix('categories')->group(function () {
        Route::get('/', 'index');
        Route::get('/{category}', 'show');
        Route::post('/', 'store');
        Route::put('/{category}', 'update');
        Route::post('/{category}', 'update'); // multipart image replace
        Route::delete('/{category}', 'destroy');
        Route::post('/{category}/toggle', 'toggle');
    });

    // Attribute Types — CRUD نوع ویژگی
    Route::controller(AdminAttributeTypeController::class)->prefix('attribute-types')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{attributeType}', 'update');
        Route::delete('/{attributeType}', 'destroy');
    });

    // Attributes — CRUD ویژگی‌ها
    Route::controller(AdminAttributeController::class)->prefix('attributes')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{attribute}', 'update');
        Route::delete('/{attribute}', 'destroy');
    });

    // Attribute Values — CRUD مقادیر ویژگی
    Route::controller(AdminAttributeValueController::class)->prefix('attribute-values')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{attributeValue}', 'update');
        Route::delete('/{attributeValue}', 'destroy');
    });

    // Factories — CRUD کارخانه‌ها (آپلود لوگو با POST/multipart)
    Route::controller(AdminFactoryController::class)->prefix('factories')->group(function () {
        Route::get('/', 'index');
        Route::get('/{factory}', 'show');
        Route::post('/', 'store');
        Route::put('/{factory}', 'update');
        Route::post('/{factory}', 'update'); // multipart logo replace
        Route::delete('/{factory}', 'destroy');
        Route::post('/{factory}/toggle', 'toggle');
    });

    // Factory Locations — CRUD انبارها/مبادی کارخانه
    Route::controller(AdminFactoryLocationController::class)->prefix('factory-locations')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{factoryLocation}', 'update');
        Route::delete('/{factoryLocation}', 'destroy');
        Route::post('/{factoryLocation}/toggle', 'toggle');
    });

    // Products — CRUD محصولات + رسانه (مسیر با cuid؛ آپلود با POST/multipart)
    Route::controller(AdminProductController::class)->prefix('products')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('/{product}', 'show');
        Route::put('/{product}', 'update');
        Route::post('/{product}', 'update'); // multipart-friendly update
        Route::delete('/{product}', 'destroy');
        Route::post('/{product}/toggle', 'toggle');
        Route::post('/{product}/toggle-featured', 'toggleFeatured');
        // Media
        Route::post('/{product}/images', 'addImage');
        Route::delete('/{product}/images/{image}', 'deleteImage');
        Route::post('/{product}/images/{image}/primary', 'setPrimaryImage');
        Route::post('/{product}/videos', 'addVideo');
        Route::delete('/{product}/videos/{video}', 'deleteVideo');
    });

    // Product Variants — واریانت‌ها (لیست/ساخت زیر محصول، بقیه با id واریانت)
    Route::controller(AdminProductVariantController::class)->group(function () {
        Route::get('/products/{product}/variants', 'index');
        Route::post('/products/{product}/variants', 'store');
        Route::get('/variants/{variant}', 'show');
        Route::put('/variants/{variant}', 'update');
        Route::post('/variants/{variant}', 'update'); // multipart-friendly update
        Route::delete('/variants/{variant}', 'destroy');
        Route::post('/variants/{variant}/toggle', 'toggle');
        Route::post('/variants/{variant}/attributes', 'syncAttributes');
    });

    // Product Prices — قیمت محصول per (واریانت × کارخانه × انبار)
    Route::controller(AdminProductPriceController::class)->prefix('product-prices')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{price}', 'update');
        Route::delete('/{price}', 'destroy');
    });

    // Price Filter Types — نوع فیلتر قیمت
    Route::controller(AdminPriceFilterTypeController::class)->prefix('price-filter-types')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{filterType}', 'update');
        Route::delete('/{filterType}', 'destroy');
        Route::post('/{filterType}/toggle', 'toggle');
    });

    // Price Filters — فیلترهای قیمت
    Route::controller(AdminPriceFilterController::class)->prefix('price-filters')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{filter}', 'update');
        Route::delete('/{filter}', 'destroy');
        Route::post('/{filter}/toggle', 'toggle');
    });

    // Price Filter Categories — دسته‌های فیلتر قیمت (+ اتصال فیلترها)
    Route::controller(AdminPriceFilterCategoryController::class)->prefix('price-filter-categories')->group(function () {
        Route::get('/', 'index');
        Route::get('/{filterCategory}', 'show');
        Route::post('/', 'store');
        Route::put('/{filterCategory}', 'update');
        Route::delete('/{filterCategory}', 'destroy');
        Route::post('/{filterCategory}/toggle', 'toggle');
        Route::post('/{filterCategory}/filters', 'syncFilters');
    });

    // Customer Types — انواع مشتری
    Route::controller(AdminCustomerTypeController::class)->prefix('customer-types')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{customerType}', 'update');
        Route::delete('/{customerType}', 'destroy');
    });

    // Price Adjustments — تعدیل قیمت (نوع مشتری × نشان)
    Route::controller(AdminPriceAdjustmentController::class)->prefix('price-adjustments')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{priceAdjustment}', 'update');
        Route::delete('/{priceAdjustment}', 'destroy');
    });

    // Weighbridge (note: at /admin root, not nested under /orders)
    Route::post('/{order}/weighbridge', [OrderWeighbridgeController::class, 'submitWeight'])
        ->name('admin.orders.weighbridge.submit');
});
