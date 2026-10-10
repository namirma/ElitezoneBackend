<?php

use App\Http\Controllers\Api\Admin\AdminActivityLogController;
use App\Http\Controllers\Api\Admin\AdminArticleController;
use App\Http\Controllers\Api\Admin\AdminAttributeController;
use App\Http\Controllers\Api\Admin\AdminAttributeTypeController;
use App\Http\Controllers\Api\Admin\AdminAttributeValueController;
use App\Http\Controllers\Api\Admin\AdminAvatarModerationController;
use App\Http\Controllers\Api\Admin\AdminBadgeController;
use App\Http\Controllers\Api\Admin\AdminBannerController;
use App\Http\Controllers\Api\Admin\AdminCouponController;
use App\Http\Controllers\Api\Admin\AdminCouponFundingController;
use App\Http\Controllers\Api\Admin\AdminCouponWalletController;
use App\Http\Controllers\Api\Admin\AdminCustomerTypeController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminDebtController;
use App\Http\Controllers\Api\Admin\AdminEduixController;
use App\Http\Controllers\Api\Admin\AdminFactoryController;
use App\Http\Controllers\Api\Admin\AdminFactoryFeatureController;
use App\Http\Controllers\Api\Admin\AdminFactoryLocationController;
use App\Http\Controllers\Api\Admin\AdminFactoryStockController;
use App\Http\Controllers\Api\Admin\AdminFaqCategoryController;
use App\Http\Controllers\Api\Admin\AdminFaqController;
use App\Http\Controllers\Api\Admin\AdminFinancialController;
use App\Http\Controllers\Api\Admin\AdminJobExperienceController;
use App\Http\Controllers\Api\Admin\AdminManagerController;
use App\Http\Controllers\Api\Admin\AdminMenuItemController;
use App\Http\Controllers\Api\Admin\AdminNotificationController;
use App\Http\Controllers\Api\Admin\AdminOrderCancelRequestController;
use App\Http\Controllers\Api\Admin\AdminOrderController;
use App\Http\Controllers\Api\Admin\AdminOrderUnitController;
use App\Http\Controllers\Api\Admin\AdminPageBlockController;
use App\Http\Controllers\Api\Admin\AdminPageController;
use App\Http\Controllers\Api\Admin\AdminPodiumDepositController;
use App\Http\Controllers\Api\Admin\AdminPriceAdjustmentController;
use App\Http\Controllers\Api\Admin\AdminPriceFilterCategoryController;
use App\Http\Controllers\Api\Admin\AdminPriceFilterController;
use App\Http\Controllers\Api\Admin\AdminPriceFilterTypeController;
use App\Http\Controllers\Api\Admin\AdminPriceSettingController;
use App\Http\Controllers\Api\Admin\AdminProductCategoryController;
use App\Http\Controllers\Api\Admin\AdminProductChangeRequestController;
use App\Http\Controllers\Api\Admin\AdminProductController;
use App\Http\Controllers\Api\Admin\AdminProductPriceController;
use App\Http\Controllers\Api\Admin\AdminProductVariantController;
use App\Http\Controllers\Api\Admin\AdminPromoOverlayController;
use App\Http\Controllers\Api\Admin\AdminReportController;
use App\Http\Controllers\Api\Admin\AdminReviewController;
use App\Http\Controllers\Api\Admin\AdminSearchController;
use App\Http\Controllers\Api\Admin\AdminSettingController;
use App\Http\Controllers\Api\Admin\AdminShippingMethodController;
use App\Http\Controllers\Api\Admin\AdminShippingRateController;
use App\Http\Controllers\Api\Admin\AdminSmsLogController;
use App\Http\Controllers\Api\Admin\AdminSmsTemplateController;
use App\Http\Controllers\Api\Admin\AdminSupplierController;
use App\Http\Controllers\Api\Admin\AdminSupplierRequestController;
use App\Http\Controllers\Api\Admin\AdminSystemHealthController;
use App\Http\Controllers\Api\Admin\AdminTicketController;
use App\Http\Controllers\Api\Admin\AdminUserBankAccountController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AdminWalletController;
use App\Http\Controllers\Api\DebugController;
use App\Http\Controllers\Api\Podium\IdentityInquirySc2Controller;
use App\Http\Controllers\Api\Podium\MobileNidMatchController;
use App\Http\Controllers\Api\Podium\PodiumBalanceController;
use App\Http\Controllers\Api\Podium\PodiumBatchSettlementController;
use App\Http\Controllers\Api\Podium\PodiumSettlementController;
use App\Http\Controllers\Api\Podium\PodiumSettlementInquiryController;
use App\Http\Controllers\Api\Shipping\ReturnDamageController;
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
// سطح ۲ — این گروه پولِ واقعی از کیف پولِ شرکت بیرون می‌فرستد (تسویه‌ی PAYA/SATNA)
// و استعلام‌های پولی می‌زند. کارشناس (سطح ۳) نباید به آن برسد.
Route::prefix('v1/podium')
    ->middleware(['auth:sanctum', 'role.admin', 'admin.ip', 'admin.permission:manage-financial', 'admin.level:2'])
    ->group(function () {
        Route::post('/settlements/single', [PodiumSettlementController::class, 'single']);
        Route::get('/balances', [PodiumBalanceController::class, 'index']);
        Route::post('/settlements/batch', [PodiumBatchSettlementController::class, 'store']);
        Route::get('/settlements/inquiry', [PodiumSettlementInquiryController::class, 'index']);
        Route::post('/identity-inquiry-sc2', IdentityInquirySc2Controller::class);
        Route::post('/mobile-nid-match', [MobileNidMatchController::class, 'store']);
    });

// ── Admin panel (auth + role:admin) ───────────────────────────────────────
Route::middleware(['auth:sanctum', 'role.admin', 'admin.ip'])->prefix('admin')->group(function () {

    // Dashboard — KPI اجرایی، پشتِ manage-financial.
    // هر پنج بلوکِ پاسخ مالی/سفارشی‌اند (فروش امروز، pipeline سفارش‌ها، هشدارهای مالی،
    // روند هفتگی، خلاصه‌ی ماه) — هیچ‌چیزی در آن نیست که ادمینِ محتوا لازم داشته باشد،
    // پس همان مجوزی را می‌گیرد که /admin/financial/* می‌گیرد.
    //
    // پیامد سمت کلاینت: ادمینی که manage-financial ندارد روی صفحه‌ی خانه‌ی پنل ۴۰۳
    // می‌گیرد. پنل باید کاربر را به اولین تبی که مجوزش را دارد بفرستد، نه اینکه
    // داشبورد را صفحه‌ی فرود ثابت فرض کند.
    Route::get('/dashboard', [AdminDashboardController::class, 'index'])
        ->middleware('admin.permission:manage-financial');

    // سلامتِ سامانه — صف، پشتیبان، دفترِ مالی و سفارش‌های گیرکرده در یک پاسخ.
    // پشتِ manage-financial چون بخشِ «دفتر» شمارِ مغایرت‌های مالی را برمی‌گرداند.
    Route::get('/system-health', AdminSystemHealthController::class)
        ->middleware('admin.permission:manage-financial')
        ->name('admin.system-health');

    // Debug helpers — هرگز در پروداکشن در دسترس نباشند
    if (! app()->environment('production')) {
        Route::get('/debug/test-cache', [DebugController::class, 'testCache']);
        Route::get('/debug/kavir-products', [DebugController::class, 'kavirProducts']);
    }

    // Activity log — ردِ ممیزیِ کلِ پنل: چه کسی تنظیمات/کوپن/مدیران/مجوزها را عوض کرد.
    // پشت manage-users چون همان تیری است که /admin/admins را دارد (حاکمیتِ دسترسی‌ها)؛
    // دیدنِ اینکه به چه کسی چه مجوزی داده شده نباید برای هر ادمینی باز باشد.
    Route::get('/activities', [AdminActivityLogController::class, 'index'])
        ->middleware('admin.permission:manage-users');

    // Search — هر endpoint پشت مجوزِ همان داده‌ای که برمی‌گرداند.
    // قبلاً هیچ‌کدام مجوز نداشتند در حالی که /admin/users پشت manage-users بود:
    // یعنی جستجوی کاربر و تراکنش همان داده را بدون دروازه برمی‌گرداند.
    Route::controller(AdminSearchController::class)->prefix('search')->group(function () {
        Route::get('/users', 'searchUsers')->middleware('admin.permission:manage-users');
        Route::get('/transactions', 'searchTransactions')->middleware('admin.permission:manage-financial');
    });

    // Users — back-office lookup endpoints (group 1)
    // این گروه همان اطلاعات هویتی/مالیِ گروه ۲ را برمی‌گرداند (پروفایل کامل، سفارش‌ها،
    // سابقه‌ی تراکنش) ولی بدونِ مجوز باز بود — یک ناسازگاری در سطح‌بندی. حالا هر دو
    // گروه پشت manage-users هستند.
    Route::prefix('users')->controller(AdminUserController::class)->middleware('admin.permission:manage-users')->group(function () {
        Route::post('/fetch-profile', 'getUserProfileForBackOffice');
        Route::get('/{user}/orders', 'getUserOrders');
        Route::get('/{user}/transactions', 'getUserTransactions');
    });

    // Financial documents + health
    Route::controller(AdminFinancialController::class)->prefix('financial')->middleware('admin.permission:manage-financial')->group(function () {
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
    Route::controller(AdminPodiumDepositController::class)->prefix('financial/podium-deposits')->middleware('admin.permission:manage-financial')->group(function () {
        Route::get('/', 'index');  // لیست سوابق + آمار
        Route::post('/poll', 'poll');   // اجرای دستی polling (بدون cron)
        Route::post('/{record}/credit', 'credit'); // شارژ دستی برای واریز unmatched
    });

    // Users — CRUD (group 2)
    Route::controller(AdminUserController::class)->prefix('users')->middleware('admin.permission:manage-users')->group(function () {
        Route::get('/', 'index');
        Route::get('/{user}', 'show');
        // سطح ۲ — غیرفعال‌کردنِ حساب همه‌ی نشست‌های کاربر را می‌بندد و او را از
        // سایت بیرون می‌اندازد؛ کارِ کارشناسِ پشتیبانی نیست.
        Route::post('/{user}/toggle-status', 'toggleStatus')->middleware('admin.level:2');
        Route::post('/{user}/note', 'addNote');
        // سقف اعتبار — بند ۳.۱۲: تنها راهِ دادنِ اعتبار به یک خریدار.
        Route::post('/{user}/credit-limit', 'setCreditLimit');
        Route::post('/{user}/verify-elite-yar', 'verifyEliteYAR');
        Route::post('/{user}/unverify-elite-yar', 'unverifyEliteYAR');
    });

    // حساب‌های بانکیِ کاربر — تنها جایی که حساب حذف می‌شود (علی ۱۴۰۵/۰۷/۱۹).
    // manage-financial چون مقصدِ پولِ برداشت است؛ حذف سطح ۲، همان سقفِ تأییدِ برداشت.
    // scopeBindings: حسابِ کاربرِ دیگر زیرِ این {user} ۴۰۴ است.
    Route::controller(AdminUserBankAccountController::class)->prefix('users/{user}/bank-accounts')
        ->middleware('admin.permission:manage-financial')->scopeBindings()->group(function () {
            Route::get('/', 'index');
            Route::delete('/{bankAccount}', 'destroy')->whereNumber('bankAccount')->middleware('admin.level:2');
        });

    // صفِ درخواست‌های تأمین‌کننده (لغوِ سفارش، افزودنِ کالا).
    //
    // ⚠️ `approve` اینجا فقط تصمیم را ثبت می‌کند و **هیچ لغوی انجام نمی‌دهد**:
    // بند ۳.۶ لغوِ اجباری را به تیکت و دلیل مشروط کرده، پس اجرای واقعی از
    // `POST /admin/orders/{order}/force-cancel` می‌گذرد تا آن دو شرط دور
    // زده نشوند.
    Route::controller(AdminSupplierRequestController::class)
        ->prefix('supplier-requests')
        ->middleware('admin.permission:manage-orders')
        ->group(function () {
            Route::get('/', 'index');
            Route::post('/{supplierRequest}/approve', 'approve')->whereNumber('supplierRequest');
            Route::post('/{supplierRequest}/reject', 'reject')->whereNumber('supplierRequest');
        });

    // صفِ لغوهای ارجاع‌شده — پرسش ۴ مدیریت (۲۰۲۶-۰۹-۰۹): درخواستِ لغوی که طرفِ
    // مقابل رد کرده یا در ۴۸ ساعت پاسخ نداده، این‌جا تصمیمِ نهایی می‌گیرد.
    //
    // ⚠️ برخلافِ صفِ بالا، `approve` این‌جا **واقعاً لغو می‌کند** و پول را از
    // تأمین‌کننده به خریدار برمی‌گرداند (`CancelOrderAction`). شرط‌های بند ۳.۶
    // به شکلِ خودشان برقرارند — پرونده‌ی درخواست جای تیکت، و `reason` اجباری.
    // سطح ۲ روی پذیرش، مثلِ `orders/{order}/force-cancel`: جابه‌جاییِ واقعیِ پول.
    Route::controller(AdminOrderCancelRequestController::class)
        ->prefix('order-cancel-requests')
        ->middleware('admin.permission:manage-orders')
        ->group(function () {
            Route::get('/', 'index');
            Route::post('/{orderCancelRequest}/approve', 'approve')
                ->whereNumber('orderCancelRequest')
                ->middleware('admin.level:2');
            Route::post('/{orderCancelRequest}/reject', 'reject')->whereNumber('orderCancelRequest');
        });

    // Suppliers — مدیریت سوپلایرها (+ صف تأیید درخواست‌های سوپلایری)
    Route::controller(AdminSupplierController::class)->prefix('suppliers')->middleware('admin.permission:manage-users')->group(function () {
        Route::get('/', 'index');
        Route::get('/{user}', 'show');
        // هر سه تصمیم به متقاضی پیامک می‌دهند (RateLimitCoverageTest): سقفِ ۳۰ در دقیقه.
        Route::post('/{user}/approve', 'approve')->middleware('throttle:30,1'); // اتصال نقش supplier — تنها مسیر مجاز
        Route::post('/{user}/reject', 'reject')->middleware('throttle:30,1');
        // «نیاز به اصلاح» — حالتِ سومِ صفِ صلاحیت (اسپکِ یکپارچه‌سازی §۴-۵): نقش وصل
        // نمی‌شود، متقاضی دلیل را می‌بیند و با ارسالِ دوباره به صف برمی‌گردد.
        Route::post('/{user}/request-correction', 'requestCorrection')->middleware('throttle:30,1');
        // مدارکِ متقاضی از دیسکِ خصوصی — همان فایل، از مسیرِ ادمین (بدونِ حدسِ نامِ فایل).
        Route::get('/{user}/documents/{document}/file', 'downloadDocument')->whereNumber('document');
        // دو لایه، چون دو پرسشِ متفاوت‌اند و هر دو باید پاس شوند:
        //
        //   manage-financial → «به کدام بخش دسترسی دارد». `updateFormula` فلگ‌های
        //     trans_commission/trans_vat را می‌نویسد که تعیین می‌کنند کدام‌یک از
        //     چهار واریانتِ POO/PTS اجرا شود؛ ادمینِ پشتیبانیِ کاربران نباید بتواند
        //     نحوه‌ی محاسبه‌ی کمیسیون و مالیات را عوض کند.
        //
        //   admin.level:1 → «تا کجا حق دارد». این فلگ‌ها روی هر سفارشِ بعدیِ آن
        //     تأمین‌کننده snapshot می‌شوند و پس از پرداخت غیرقابلِ بازگشت‌اند، پس
        //     تغییرشان در سطحِ مالکِ برند است نه هر مدیرِ مالی.
        Route::put('/{user}/formula', 'updateFormula')
            ->middleware(['admin.permission:manage-financial', 'admin.level:1']);
        Route::get('/{user}/orders', 'orders');
    });

    // Orders — پشتِ مجوزِ granular «manage-orders» (قبلاً این slug به هیچ روتی وصل نبود،
    // پس ادمینِ محدودشده هم می‌توانست وضعیت سفارش/فاکتور مودیان را تغییر دهد).
    // گزارش‌های فاز ۱۳ — سبدهای رهاشده و بدهی (بند ۷.۶). فقط خواندنی.
    Route::controller(AdminDebtController::class)->group(function () {
        Route::get('/abandoned-carts', 'abandonedCarts')
            ->middleware('admin.permission:manage-orders');
        Route::get('/debts', 'debts')
            ->middleware('admin.permission:manage-financial');
        Route::get('/users/{user}/debt-entries', 'userDebtEntries')
            ->middleware('admin.permission:manage-financial');
    });

    // خسارت عودت — تأیید بک‌آفیس، مرحله‌ی دومِ جریان سه‌مرحله‌ای (بند ۳.۱۱).
    Route::post('/damages/{damage}/approve', [ReturnDamageController::class, 'approveByBackoffice'])
        ->whereNumber('damage')
        ->middleware('admin.permission:manage-orders');

    // محموله‌ها — تأیید تلفنی رسیدن توسط بک‌آفیس (بند ۳.۱۰).
    Route::controller(AdminOrderController::class)->prefix('shipments')
        ->middleware('admin.permission:manage-orders')->group(function () {
            Route::post('/{shipment}/confirm-arrival', 'confirmShipmentArrival')->whereNumber('shipment');

            // adapter دستی (§۷.۶ / بند ۳.۷) — «ثبت دستی با تأیید بک‌آفیس».
            // فقط وزن می‌نویسد؛ تعدیل مالی جای دیگری و یک‌بار اجرا می‌شود.
            Route::post('/{shipment}/weight', 'recordShipmentWeight')->whereNumber('shipment');
        });

    Route::controller(AdminOrderController::class)->prefix('orders')->middleware('admin.permission:manage-orders')->group(function () {
        Route::get('/', 'index');
        Route::get('/{order}/history', 'history');
        // روایتِ کاملِ سفارش (چرخه‌ی عمر + وضعیت + پول + اسناد در یک خط زمانی).
        // مثل /history باید پیش از wildcard /{order} بیاید.
        Route::get('/{order}/timeline', 'timeline');
        Route::get('/{order}', 'show');
        // همان لغوِ اجباری (`changeStatus` → `forceCancel`)، پس همان سقفِ سطح ۲ (BE7).
        Route::post('/{order}/status', 'changeStatus')->middleware('admin.level:2');

        // §۷.۶ — لغو اجباری با نامِ صریح. `changeStatus` همین کار را می‌کرد ولی
        // پشت یک اندپوینتِ عمومیِ «تغییر وضعیت» پنهان بود، در حالی که بند ۵.۳
        // می‌گوید **تنها اختیار ادمین روی وضعیت، لغو اجباری است**. اسمِ درست
        // یعنی لاگِ ممیزی و مجوزها هم همان چیزی را بگویند که واقعاً رخ می‌دهد.
        //
        // سطح ۲ از برنچِ `refactor/…` می‌آید: لغوِ سفارشِ پرداخت‌شده استرداد
        // می‌زند، پس جابه‌جاییِ واقعیِ پول است.
        Route::post('/{order}/force-cancel', 'forceCancel')
            ->whereNumber('order')
            ->middleware('admin.level:2');

        // پرسشِ ۱۴ مدیریت (۱۴۰۵/۰۷/۱۳، گزینه‌ی الف): توضیحِ آزادِ بک‌آفیس روی سفارش
        // («توضیح شرایط خاص»، مثلاً تأخیرِ تحویلِ کالای پرداخت‌شده) که خریدار و
        // تأمین‌کننده در پروفایل می‌بینند؛ خالی ⇒ چیزی نمایش داده نمی‌شود.
        Route::put('/{order}/note', 'updateNote')
            ->whereNumber('order');

        // Doc P — فاکتور مودیان سیستم (سازه → خریدار)، آزادسازی WVAT hold.
        // سطح ۲: آزادسازیِ hold یعنی جابه‌جاییِ واقعیِ پول بین کیف‌پول‌ها.
        Route::post('/{order}/system-moadian-invoice', 'submitSystemMoadianInvoice')
            ->middleware('admin.level:2');

        // ⚠️ **باسکول عمداً اینجا نیست و نباید برگردد** (بند ۱۶ — فاز ۷).
        //
        // برنچِ `refactor/consultant-to-elite-yar` این روت را با
        // `admin.level:2` دوباره اضافه کرده بود؛ در ادغام عمداً نگرفته شد.
        // وزن حالا همراهِ بارنامه و مشخصاتِ راننده توسط **تأمین‌کننده** ثبت
        // می‌شود: `POST /profile/orders/{order}/shipments/{shipment}/waybill`.
        // ادمین دیگر نقطه‌ی ورودی به باسکول ندارد — نه این روت، نه aliasِ
        // قدیمیِ بی‌مجوزِ ریشه‌ی /admin.
        //
        // ‏`Admin/AdminPermissionCoverageTest::the_weighbridge_has_no_admin_entry_point_left`
        // این را قفل کرده؛ اگر برگردد، سوئیت قرمز می‌شود.
    });

    // Tickets — مدیریت تیکت‌های پشتیبانی — پشتِ مجوزِ granular «manage-tickets».
    Route::controller(AdminTicketController::class)->prefix('tickets')->middleware('admin.permission:manage-tickets')->group(function () {
        Route::get('/', 'index');
        Route::get('/kpis', 'kpis'); // باید پیش از wildcard /{ticket} باشد
        Route::get('/departments', 'departments'); // باید پیش از wildcard /{ticket} باشد
        Route::get('/agents', 'agents');           // کارشناسانِ قابل‌ارجاع — پیش از wildcard
        Route::get('/{ticket}', 'show');
        Route::post('/{ticket}/reply', 'reply');
        Route::post('/{ticket}/status', 'changeStatus');
        Route::post('/{ticket}/note', 'addNote');
        Route::post('/{ticket}/assign', 'assign');               // اختصاص به کارشناس
        Route::post('/{ticket}/department', 'moveDepartment');    // انتقال دپارتمان
        Route::post('/{ticket}/priority', 'setPriority');         // اسکالیشن (اولویت)
    });

    // Wallet
    Route::controller(AdminWalletController::class)->prefix('wallet')->middleware('admin.permission:manage-financial')->group(function () {
        Route::get('/transactions', 'index');
        Route::get('/transactions/{transaction}', 'show');
        // تأیید/ردِ واریز و برداشت = جابه‌جاییِ واقعیِ پول → دستِ‌کم سطح ۲.
        // خواندنِ فهرست برای کارشناس آزاد است تا بتواند به تیکت پاسخ دهد.
        Route::post('/transactions/{transaction}/status', 'changeStatus')->middleware('admin.level:2');
    });

    // Shipping Rates — CRUD نرخ‌های حمل‌ونقل
    Route::controller(AdminShippingRateController::class)->prefix('shipping-rates')->middleware('admin.permission:manage-catalog')->group(function () {
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
    Route::controller(AdminJobExperienceController::class)->prefix('job-experiences')->middleware('admin.permission:manage-users')->group(function () {
        Route::get('/', 'index');
        Route::get('/{jobExperience}', 'show');
        Route::get('/{jobExperience}/file', 'download')->name('admin.job-experiences.file');
        Route::post('/{jobExperience}/approve', 'approve');
        Route::post('/{jobExperience}/reject', 'reject');
    });

    // Education records (Eduix) — صفِ تأیید مدارک تحصیلی
    Route::controller(AdminEduixController::class)->prefix('education-records')->middleware('admin.permission:manage-users')->group(function () {
        Route::get('/', 'index');
        Route::get('/{eduixRecord}', 'show');
        Route::post('/{eduixRecord}/approve', 'approve');
        Route::post('/{eduixRecord}/reject', 'reject');
    });

    // ══════════════════════════════════════════════════════════════════════
    // Catalog management — مدیریت کاتالوگ (محصول/دسته/ویژگی/قیمت/...)
    // ══════════════════════════════════════════════════════════════════════

    // Badges — CRUD نشان‌ها (آپلود آیکون با POST/multipart)
    Route::controller(AdminBadgeController::class)->prefix('badges')->middleware('admin.permission:manage-catalog')->group(function () {
        Route::get('/', 'index');
        Route::get('/{badge}', 'show');
        Route::post('/', 'store');
        Route::put('/{badge}', 'update');
        Route::post('/{badge}', 'update'); // multipart file replace (PHP can't parse uploads on raw PUT)
        Route::delete('/{badge}', 'destroy');
    });

    // Product Categories — CRUD دسته‌بندی‌ها (آپلود تصویر با POST/multipart)
    Route::controller(AdminProductCategoryController::class)->prefix('categories')->middleware('admin.permission:manage-catalog')->group(function () {
        Route::get('/', 'index');
        Route::get('/{category}', 'show');
        Route::post('/', 'store');
        Route::put('/{category}', 'update');
        Route::post('/{category}', 'update'); // multipart image replace
        Route::delete('/{category}', 'destroy');
        Route::post('/{category}/toggle', 'toggle');
    });

    // Attribute Types — CRUD نوع ویژگی
    Route::controller(AdminAttributeTypeController::class)->prefix('attribute-types')->middleware('admin.permission:manage-catalog')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{attributeType}', 'update');
        Route::delete('/{attributeType}', 'destroy');
    });

    // Attributes — CRUD ویژگی‌ها
    Route::controller(AdminAttributeController::class)->prefix('attributes')->middleware('admin.permission:manage-catalog')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{attribute}', 'update');
        Route::delete('/{attribute}', 'destroy');
    });

    // Attribute Values — CRUD مقادیر ویژگی
    Route::controller(AdminAttributeValueController::class)->prefix('attribute-values')->middleware('admin.permission:manage-catalog')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{attributeValue}', 'update');
        Route::delete('/{attributeValue}', 'destroy');
    });

    // Factories — CRUD کارخانه‌ها (آپلود لوگو با POST/multipart)
    Route::controller(AdminFactoryController::class)->prefix('factories')->middleware('admin.permission:manage-catalog')->group(function () {
        Route::get('/', 'index');
        Route::get('/{factory}', 'show');
        Route::post('/', 'store');
        Route::put('/{factory}', 'update');
        Route::post('/{factory}', 'update'); // multipart logo replace
        Route::delete('/{factory}', 'destroy');
        Route::post('/{factory}/toggle', 'toggle');
    });

    // Factory Locations — CRUD انبارها/مبادی کارخانه
    Route::controller(AdminFactoryLocationController::class)->prefix('factory-locations')->middleware('admin.permission:manage-catalog')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{factoryLocation}', 'update');
        Route::delete('/{factoryLocation}', 'destroy');
        Route::post('/{factoryLocation}/toggle', 'toggle');
    });

    // Factory Features — CRUD ویژگی‌های بازاریابیِ کارخانه (آیکون + متن کوتاه)
    Route::controller(AdminFactoryFeatureController::class)->prefix('factory-features')->middleware('admin.permission:manage-catalog')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{factoryFeature}', 'update');
        Route::delete('/{factoryFeature}', 'destroy');
        Route::post('/{factoryFeature}/toggle', 'toggle');
    });

    // Shipping Methods — CRUD روش‌های ارسال
    Route::controller(AdminShippingMethodController::class)->prefix('shipping-methods')->middleware('admin.permission:manage-catalog')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{shippingMethod}', 'update');
        Route::delete('/{shippingMethod}', 'destroy');
    });

    // Products — CRUD محصولات + رسانه (مسیر با cuid؛ آپلود با POST/multipart)
    Route::controller(AdminProductController::class)->prefix('products')->middleware('admin.permission:manage-catalog')->group(function () {
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
        // واحدهای سفارشِ مجاز + سقف تعداد (پیوت). بدون ردیف، افزودن به سبد ۴۲۲ می‌شود.
        Route::post('/{product}/order-units', 'syncOrderUnits');
    });

    // صفِ درخواست‌های تغییرِ کاتالوگ از سوی تأمین‌کننده‌ها (ساخت/ویرایشِ محصول و
    // واریانت). ⚠️ پذیرش **اعمال می‌کند** — از همان `ProductCatalogWriter`ی که
    // مسیرهای `products` و `variants` بالا/پایین می‌نویسند. زیرِ `manage-catalog`
    // چون نتیجه‌اش دقیقاً همان نوشتنِ کاتالوگ است.
    Route::controller(AdminProductChangeRequestController::class)
        ->prefix('product-change-requests')
        ->middleware('admin.permission:manage-catalog')
        ->group(function () {
            Route::get('/', 'index');
            Route::get('/{changeRequest}', 'show')->whereNumber('changeRequest');
            Route::post('/{changeRequest}/approve', 'approve')->whereNumber('changeRequest');
            Route::post('/{changeRequest}/reject', 'reject')->whereNumber('changeRequest');
        });

    // Order Units — واحدهای سفارش (ظرفیت/بندیل/خرده‌فروشی…). `amount` ضریب تبدیل به
    // کیلوگرم است و مبنای وزنِ سبد، کرایه‌ی حمل و تسویه — پس همان سطحِ کاتالوگ را می‌گیرد.
    Route::controller(AdminOrderUnitController::class)->prefix('order-units')->middleware('admin.permission:manage-catalog')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('/{orderUnit}', 'show');
        Route::put('/{orderUnit}', 'update');
        Route::delete('/{orderUnit}', 'destroy');
    });

    // Product Variants — واریانت‌ها (لیست/ساخت زیر محصول، بقیه با id واریانت)
    // manage-catalog قبلاً روی این گروه نبود در حالی که همه‌ی گروه‌های هم‌ردیفِ کاتالوگ
    // داشتند — واریانت مبنای قیمت و موجودی است، پس همان سطح دسترسی را می‌خواهد.
    Route::controller(AdminProductVariantController::class)->middleware('admin.permission:manage-catalog')->group(function () {
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
    Route::controller(AdminProductPriceController::class)->prefix('product-prices')->middleware('admin.permission:manage-catalog')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{price}', 'update');
        Route::delete('/{price}', 'destroy');
    });

    // Factory Stocks — موجودی به‌ازای مبدأ (کارخانه/انبار).
    // از وقتی factory_stocks مرجعِ سفارش شد، بدون این گروه عددِ اشتباهِ یک
    // تأمین‌کننده فقط با tinker قابل اصلاح بود.
    Route::controller(AdminFactoryStockController::class)->prefix('factory-stocks')->middleware('admin.permission:manage-catalog')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{stock}', 'update');
        Route::delete('/{stock}', 'destroy');
    });

    // Price Filter Types — نوع فیلتر قیمت
    Route::controller(AdminPriceFilterTypeController::class)->prefix('price-filter-types')->middleware('admin.permission:manage-catalog')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{filterType}', 'update');
        Route::delete('/{filterType}', 'destroy');
        Route::post('/{filterType}/toggle', 'toggle');
    });

    // Price Filters — فیلترهای قیمت
    Route::controller(AdminPriceFilterController::class)->prefix('price-filters')->middleware('admin.permission:manage-catalog')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{filter}', 'update');
        Route::delete('/{filter}', 'destroy');
        Route::post('/{filter}/toggle', 'toggle');
    });

    // Price Filter Categories — دسته‌های فیلتر قیمت (+ اتصال فیلترها)
    Route::controller(AdminPriceFilterCategoryController::class)->prefix('price-filter-categories')->middleware('admin.permission:manage-catalog')->group(function () {
        Route::get('/', 'index');
        Route::get('/{filterCategory}', 'show');
        Route::post('/', 'store');
        Route::put('/{filterCategory}', 'update');
        Route::delete('/{filterCategory}', 'destroy');
        Route::post('/{filterCategory}/toggle', 'toggle');
        Route::post('/{filterCategory}/filters', 'syncFilters');
    });

    // Customer Types — انواع مشتری
    Route::controller(AdminCustomerTypeController::class)->prefix('customer-types')->middleware('admin.permission:manage-catalog')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{customerType}', 'update');
        Route::delete('/{customerType}', 'destroy');
    });

    // Price Adjustments — تعدیل قیمت (نوع مشتری × نشان)
    Route::controller(AdminPriceAdjustmentController::class)->prefix('price-adjustments')->middleware('admin.permission:manage-catalog')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{priceAdjustment}', 'update');
        Route::delete('/{priceAdjustment}', 'destroy');
    });

    // ══════════════════════════════════════════════════════════════════════
    // Content & BackOffice modules — نیازمندی‌های پنل ادمین فرانت
    // ══════════════════════════════════════════════════════════════════════

    // Banners — CRUD بنرها (آپلود تصویر با POST/multipart)
    Route::controller(AdminBannerController::class)->prefix('banners')->middleware('admin.permission:manage-content')->group(function () {
        Route::get('/', 'index');
        Route::get('/{banner}', 'show');
        Route::post('/', 'store');
        Route::put('/{banner}', 'update');
        Route::post('/{banner}', 'update'); // multipart image replace
        Route::post('/{banner}/toggle', 'toggle');
        Route::post('/{id}/restore', 'restore')->whereNumber('id');
        Route::delete('/{banner}', 'destroy');
    });

    // Promo overlays — اورلی‌های ترغیب/تبلیغاتی (آپلود تصویر با POST/multipart).
    // اورلی‌های سیستمی (welcome / select_role / select_elite_yar) ویرایش‌پذیرند
    // ولی destroy رویشان ۴۲۲ می‌دهد — خاموش‌کردنشان با toggle است.
    Route::controller(AdminPromoOverlayController::class)->prefix('promo-overlays')->middleware('admin.permission:manage-content')->group(function () {
        Route::get('/', 'index');
        Route::get('/{promoOverlay}', 'show');
        Route::post('/', 'store');
        Route::put('/{promoOverlay}', 'update');
        Route::post('/{promoOverlay}', 'update'); // multipart image replace
        Route::post('/{promoOverlay}/toggle', 'toggle');
        Route::post('/{id}/restore', 'restore')->whereNumber('id');
        Route::delete('/{promoOverlay}', 'destroy');
    });

    // Articles — CRUD مقالات (آپلود کاور با POST/multipart)
    Route::controller(AdminArticleController::class)->prefix('articles')->middleware('admin.permission:manage-content')->group(function () {
        Route::get('/', 'index');
        Route::get('/{article}', 'show');
        Route::post('/', 'store');
        Route::put('/{article}', 'update');
        Route::post('/{article}', 'update'); // multipart cover replace
        Route::post('/{article}/toggle', 'toggle');
        Route::post('/{id}/restore', 'restore')->whereNumber('id');
        Route::delete('/{article}', 'destroy');
    });

    // FAQ — سؤالات متداول
    Route::controller(AdminFaqController::class)->prefix('faq')->middleware('admin.permission:manage-content')->group(function () {
        Route::get('/', 'index');
        Route::get('/{faq}', 'show');
        Route::post('/', 'store');
        Route::put('/{faq}', 'update');
        Route::post('/{faq}/toggle', 'toggle');
        Route::post('/{id}/restore', 'restore')->whereNumber('id');
        Route::delete('/{faq}', 'destroy');
    });

    // FAQ categories — دسته‌بندی سوالات (منوی کشوی پشتیبانیِ سایت اصلی)
    Route::controller(AdminFaqCategoryController::class)->prefix('faq-categories')->middleware('admin.permission:manage-content')->group(function () {
        Route::get('/', 'index');
        Route::get('/{faqCategory}', 'show');
        Route::post('/', 'store');
        Route::put('/{faqCategory}', 'update');
        Route::post('/{faqCategory}/toggle', 'toggle');
        Route::post('/{id}/restore', 'restore')->whereNumber('id');
        Route::delete('/{faqCategory}', 'destroy');
    });

    // Menu items — مگامنوی نوار بالای سایت اصلی
    Route::controller(AdminMenuItemController::class)->prefix('menu-items')->middleware('admin.permission:manage-content')->group(function () {
        Route::get('/', 'index');
        Route::get('/{menuItem}', 'show');
        Route::post('/', 'store');
        Route::put('/{menuItem}', 'update');
        Route::post('/{menuItem}/toggle', 'toggle');
        Route::post('/{id}/restore', 'restore')->whereNumber('id');
        Route::delete('/{menuItem}', 'destroy');
    });

    // Settings — تنظیمات key-value سایت
    // خواندن برای هر ادمینِ محتوا آزاد است، ولی نوشتن سطح ۱ می‌خواهد: این کلیدها
    // هویتِ عمومیِ برندند (تلفن، ایمیل، شبکه‌ها، نمادهای اعتماد) و یک مقدارِ غلط
    // مستقیماً روی صفحه‌ی همه‌ی بازدیدکنندگان می‌نشیند.
    Route::controller(AdminSettingController::class)->prefix('settings')->middleware('admin.permission:manage-content')->group(function () {
        Route::get('/', 'index');
        Route::put('/', 'update')->middleware('admin.level:1');
    });

    // پارامترهای مالیِ تسویه (%TC، VAT، سهم‌های OW/CO) — روی محاسبه‌ی هر سفارشِ بعدی اثر
    // دارد؛ نوشتن سطح ۱ می‌خواهد مثلِ تنظیمات سایت.
    Route::controller(AdminPriceSettingController::class)->prefix('price-settings')->middleware('admin.permission:manage-financial')->group(function () {
        Route::get('/', 'index');
        Route::put('/', 'update')->middleware('admin.level:1');
    });

    // «کیف پول کوپن‌های تخفیف» (مدیریت، دورِ پنجم، ۱۴۰۵/۰۷/۱۴) — تنها منبعِ سهمِ شرکت از کوپن.
    // جابه‌جاییِ پول بینِ کیف‌پول‌های پلتفرم ⇒ سطح ۱، مثلِ پارامترهای مالی.
    Route::controller(AdminCouponWalletController::class)->prefix('coupon-wallet')->middleware('admin.permission:manage-financial')->group(function () {
        Route::get('/', 'show');
        Route::post('/fund', 'fund')->middleware('admin.level:1');
        Route::post('/defund', 'defund')->middleware('admin.level:1');
    });

    // Notifications — اعلان‌های درون‌برنامه‌ای (لیست + ارسال + آمار)
    Route::controller(AdminNotificationController::class)->prefix('notifications')->middleware('admin.permission:manage-content')->group(function () {
        Route::get('/', 'index');
        Route::get('/stats', 'stats');
        Route::post('/send', 'send');
    });

    // SMS templates — پیامک‌های سیستم: متن، شرط، فعال/غیرفعال، قالبِ سفارشی روی رویداد.
    // `/events` پیش از `/{smsTemplate}` تا به‌عنوان شناسه خوانده نشود. built-in حذف نمی‌شود
    // (۴۲۲) و کلیدِ حفاظت‌شده (کد ورود و …) خاموش نمی‌شود (۴۲۲).
    Route::controller(AdminSmsTemplateController::class)->prefix('sms-templates')->middleware('admin.permission:manage-content')->group(function () {
        Route::get('/', 'index');
        Route::get('/events', 'events');
        Route::get('/{smsTemplate}', 'show');
        Route::post('/', 'store');
        Route::put('/{smsTemplate}', 'update');
        Route::post('/{smsTemplate}/toggle', 'toggle');
        Route::post('/{smsTemplate}/test', 'test')->middleware('throttle:10,1');
        Route::delete('/{smsTemplate}', 'destroy');
    });

    // SMS logs — گزارشِ ارسال (فقط خواندنی)
    Route::controller(AdminSmsLogController::class)->prefix('sms-logs')->middleware('admin.permission:manage-content')->group(function () {
        Route::get('/', 'index');
    });

    // Reports — خروجی CSV گزارش‌ها (بازه بزرگ → صف + اعلان + دانلود)
    Route::controller(AdminReportController::class)->prefix('reports')->middleware('admin.permission:manage-financial')->group(function () {
        Route::get('/orders', 'orders');
        Route::get('/financial', 'financial');
        Route::get('/users', 'users');
        Route::get('/downloads/{filename}', 'download');
    });

    // Admins — مدیران سیستم (destroy = فقط جداکردن نقش admin)
    // ⚠️ سطح ۱ (مدیر ارشد) — هرکس این گروه را در اختیار داشته باشد می‌تواند ادمین
    // بسازد و به خودش هر مجوزی بدهد؛ یعنی کنترلِ این گروه عملاً کنترلِ کلِ پنل است.
    // مجوزِ manage-users به‌تنهایی برای چنین اختیاری کافی نیست.
    // صفِ تأییدِ تصویرِ ادمین‌ها — سطح ۱. تصویرِ ادمین در تیکت‌های پشتیبانی کنارِ
    // نامش به مشتری نشان داده می‌شود، پس چهره‌ی برند است و تأییدش دستِ مالک است.
    Route::controller(AdminAvatarModerationController::class)->prefix('avatar-requests')
        ->middleware(['admin.permission:manage-users', 'admin.level:1'])->group(function () {
            Route::get('/', 'index');
            Route::post('/{user}/approve', 'approve');
            Route::post('/{user}/reject', 'reject');
        });

    // ⚠️ پیش از باز کردنِ این گروه برای سطح ۲: سقفِ اعطا (`ChecksGrantableAdminLevel`)
    // فقط جلوی دادنِ سطحِ بالاتر را می‌گیرد. «مدیر» هنوز می‌تواند رمزِ یک مدیر ارشد را
    // عوض کند، غیرفعالش کند یا نقشش را بردارد — تغییرِ رمزِ بالادست یعنی ورود به جای او.
    // اول یک چکِ «فقط هم‌سطح یا پایین‌تر» روی update/toggle/destroy بگذارید.
    Route::controller(AdminManagerController::class)->prefix('admins')->middleware(['admin.permission:manage-users', 'admin.level:1'])->group(function () {
        Route::get('/', 'index');
        Route::get('/{user}', 'show');
        Route::post('/', 'store');
        Route::put('/{user}', 'update');
        Route::post('/{user}/toggle', 'toggle');
        Route::delete('/{user}', 'destroy');
    });

    // Coupons — کدهای تخفیف (حذف/ویرایش کد استفاده‌شده = 422)
    Route::controller(AdminCouponController::class)->prefix('coupons')->middleware('admin.permission:manage-catalog')->group(function () {
        Route::get('/', 'index');
        Route::get('/{coupon}', 'show');
        Route::post('/', 'store');
        Route::put('/{coupon}', 'update');
        Route::post('/{coupon}/toggle', 'toggle');
        Route::delete('/{coupon}', 'destroy');
    });

    // ── تأمینِ تخفیفِ کوپن — سمتِ شرکت (docs/specs/COUPON_FUNDING_2026-09-15.md §۴) ──
    // صفِ ردیف‌های شرکت که تأمین‌کننده/الیت‌یار پیشنهاد داده‌اند + لِگ‌های تأمین‌شده.
    // همان `manage-catalog` که خودِ کوپن‌ها دارند.
    Route::controller(AdminCouponFundingController::class)->middleware('admin.permission:manage-catalog')->group(function () {
        Route::get('/coupon-funders', 'index');
        Route::post('/coupon-funders/{funder}/accept', 'accept')->whereNumber('funder');
        Route::post('/coupon-funders/{funder}/decline', 'decline')->whereNumber('funder');
        Route::get('/coupon-funding-legs', 'legs');
    });
    // ── پایانِ تأمینِ تخفیف ──

    // Reviews — صف تأیید نظرات محصول (الگوی صف‌های job-experiences)
    // پشت فلگ features.product_reviews — پیش‌فرض خاموش (config/features.php)
    if (config('features.product_reviews')) {
        Route::controller(AdminReviewController::class)->prefix('reviews')->middleware('admin.permission:manage-content')->group(function () {
            Route::get('/', 'index');
            Route::get('/{review}', 'show');
            Route::post('/{review}/approve', 'approve');
            Route::post('/{review}/reject', 'reject');
        });
    }

    // Pages — صفحات استاتیک (slug یکتا)
    Route::controller(AdminPageController::class)->prefix('pages')->middleware('admin.permission:manage-content')->group(function () {
        Route::get('/', 'index');
        Route::get('/{page}', 'show');
        Route::post('/', 'store');
        Route::put('/{page}', 'update');
        Route::post('/{page}/toggle', 'toggle');
        Route::post('/{id}/restore', 'restore')->whereNumber('id');
        Route::delete('/{page}', 'destroy');
    });

    // Page blocks — بلوک‌های صفحات محتوایی (هوم/پودیوم/فولاد کویر).
    // `/schema` باید پیش از `/{pageBlock}` بیاید، وگرنه لاراول «schema» را
    // شناسه‌ی مدل می‌فهمد و ۴۰۴ می‌دهد.
    Route::controller(AdminPageBlockController::class)->prefix('page-blocks')->middleware('admin.permission:manage-content')->group(function () {
        Route::get('/schema', 'schema');
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::post('/reorder', 'reorder');
        Route::post('/media', 'media');
        Route::post('/{pageBlock}/toggle', 'toggle');
        Route::post('/{id}/restore', 'restore');
        Route::get('/{pageBlock}', 'show');
        Route::put('/{pageBlock}', 'update');
        Route::delete('/{pageBlock}', 'destroy');
    });

});
