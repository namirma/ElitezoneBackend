<?php

use App\Http\Controllers\AgreementController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Data\AnalyticsController;
use App\Http\Controllers\Api\Data\InventoryController;
use App\Http\Controllers\Api\ListopherController;
use App\Http\Controllers\Api\User\AccountSecurityController;
use App\Http\Controllers\Api\User\AddressController;
use App\Http\Controllers\Api\User\AvatarController;
use App\Http\Controllers\Api\User\BadgeController;
use App\Http\Controllers\Api\User\BeneficiaryController;
use App\Http\Controllers\Api\User\CartController;
use App\Http\Controllers\Api\User\CheckoutController;
use App\Http\Controllers\Api\User\ContractorController;
use App\Http\Controllers\Api\User\DashboardController;
use App\Http\Controllers\Api\User\EduixController;
use App\Http\Controllers\Api\User\EliteYARInviteController;
use App\Http\Controllers\Api\User\EliteYARRelationController;
use App\Http\Controllers\Api\User\EmployerController;
use App\Http\Controllers\Api\User\EmployerInviteController;
use App\Http\Controllers\Api\User\EngineerController;
use App\Http\Controllers\Api\User\JobExperienceController;
use App\Http\Controllers\Api\User\MyItemsController;
use App\Http\Controllers\Api\User\NotificationController;
use App\Http\Controllers\Api\User\OrderActionController;
use App\Http\Controllers\Api\User\OrderController;
use App\Http\Controllers\Api\User\OrderInvoiceController;
use App\Http\Controllers\Api\User\OrderPaymentController;
use App\Http\Controllers\Api\User\OrderSurveyController;
use App\Http\Controllers\Api\User\ProductReviewController;
use App\Http\Controllers\Api\User\ProfileController;
use App\Http\Controllers\Api\User\ProfileSearchController;
use App\Http\Controllers\Api\User\StockSubscriptionController;
use App\Http\Controllers\Api\User\SupplierController;
use App\Http\Controllers\Api\User\SupplierFactoryController;
use App\Http\Controllers\Api\User\SupplierFactoryLocationController;
use App\Http\Controllers\Api\User\SupplierOrderController;
use App\Http\Controllers\Api\User\SupplierProductPriceController;
use App\Http\Controllers\Api\User\SupplierShippingRateController;
use App\Http\Controllers\Api\User\SupplierStockController;
use App\Http\Controllers\Api\User\UserBankAccountController;
use App\Http\Controllers\Api\User\WalletController;
use App\Http\Controllers\Api\User\WishlistController;
use App\Http\Controllers\Payment\WalletPaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Middleware\EnsureRoleIsSelected;
use App\Support\OrderDocumentCatalog;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authenticated User Routes
|--------------------------------------------------------------------------
| همه روتهای نیازمند لاگین کاربر. هر group بسته به نیازش middleware اضافه
| دارد (attach.price.category, role.elite_yar, idempotency, throttle, …).
*/

// ── Wallet payment + misc auth ────────────────────────────────────────────
Route::middleware(['auth:sanctum'])->group(function () {

    // درگاه پرداخت کیف پول — idempotency مثل pay-full (عملیات مالی، دابل‌کلیک‌پذیر)
    Route::post('payment/wallet', [WalletPaymentController::class, 'pay'])
        ->middleware(['idempotency', 'throttle:financial'])
        ->name('payment.wallet');

    // جستجوی نویگیشن پروفایل
    Route::prefix('profile/search-navigation')->controller(ProfileSearchController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/log', 'logSearch');
        Route::delete('/history', 'clearHistory');
    });
});

// ── Analytics & inventory ─────────────────────────────────────────────────
// داده‌های درآمد، سگمنت‌های RFM و پیش‌بینی موجودی، اطلاعات تجاری حساس‌اند — فقط ادمین
Route::prefix('data')->middleware(['auth:sanctum', 'role.admin'])->group(function () {
    Route::get('analytics/dashboard', [AnalyticsController::class, 'dashboard']);
    Route::get('inventory/predictions', [InventoryController::class, 'predictions']);
});

// ── Products (authenticated — needs pricing role) ─────────────────────────
Route::prefix('products')->middleware(['auth:sanctum', 'attach.price.category'])->group(function () {
    Route::post('/calculate-price', [ProductController::class, 'calculateOrderPrice']);
});

// ── Product reviews — ثبت نظر (هر کاربر/محصول یک نظر؛ ارسال مجدد → pending)
// پشت فلگ features.product_reviews — پیش‌فرض خاموش (config/features.php)
if (config('features.product_reviews')) {
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/products/{product:cuid}/reviews', [ProductReviewController::class, 'store'])
            ->middleware('throttle:10,1');
        Route::get('/profile/reviews', [ProductReviewController::class, 'myReviews']);
    });
}

// ── Cart — مهمان هم می‌تواند سبد داشته باشد ─────────────────────────────
Route::middleware(['optional.sanctum', 'attach.price.category'])->group(function () {
    Route::prefix('cart')->controller(CartController::class)->group(function () {
        Route::get('/', 'getCart');
        Route::post('/items', 'addToCart');
        Route::patch('/items/{item}', 'updateCartItem');
        Route::delete('/items/{item}', 'removeCartItem');
        Route::patch('/filters', 'updateCartFilters');
        Route::patch('/customer', 'updateCustomerType');
        // فقط کدِ کوپن روی سبد می‌نشیند؛ مبلغش در لحظه‌ی رزرو/پرداخت حساب
        // می‌شود (بند ۳.۱۳). throttle چون کد ورودیِ کاربر است و نباید بشود با
        // تکرار، فضای کدها را جارو کرد — هم‌سو با /checkout/coupon-preview.
        Route::post('/coupon', 'storeCoupon')->middleware('throttle:10,1');
        // استعلام هزینه حمل پیش از checkout — نیازمند آدرس کاربر (auth الزامی)
        Route::post('/shipping-quote', 'shippingQuote')->middleware('auth:sanctum');
        // پیش‌فاکتور سبدِ هنوز ثبت‌نشده — سند مالی است، پس مهمان اجازه ندارد.
        // هیچ سفارشی ساخته نمی‌شود و هیچ رکورد Invoice/شمارهٔ سندی مصرف نمی‌شود.
        Route::get('/pre-invoice', 'preInvoice')->middleware('auth:sanctum');
    });
});

// ── Checkout — نیاز به احراز هویت دارد ───────────────────────────────────
Route::middleware(['auth:sanctum', 'attach.price.category'])->group(function () {
    Route::controller(CheckoutController::class)->prefix('checkout')->group(function () {
        Route::post('/', 'placeOrder')->middleware(EnsureRoleIsSelected::class);
        Route::get('/payment/{order}', 'paymentInfo');
        // درخت تصمیم پرداخت (بند ۳.۳ / ۷.۲) — منبع واحد برای بک‌اند و فرانت.
        Route::get('/payment-options', 'paymentOptions');
        // پیش‌نمایش کد تخفیف (throttle تا brute-force کد ممکن نباشد)
        Route::post('/coupon-preview', 'couponPreview')->middleware('throttle:10,1');
    });
});

// ── Authenticated user area (profile + nested resources) ──────────────────
Route::middleware(['auth:sanctum'])->group(function () {

    // Provinces & cities (lookup helpers)
    Route::prefix('province')->controller(AddressController::class)->group(function () {
        Route::get('/', 'getProvinces');
        Route::get('/cities/{provinceID?}', 'getCities');
    });

    // ──────────────────── Profile (main user area) ────────────────────────
    Route::prefix('profile')->group(function () {

        Route::get('/dashboard', [DashboardController::class, 'index']);

        // Collaboration badge (proxied server-side from saze90.com — fixes the CORS that
        // broke the old direct frontend call). Returns { badge, is_pioneer, badges }.
        Route::get('/badge', [BadgeController::class, 'show']);

        // Basic profile data
        Route::get('/', [ProfileController::class, 'getProfile'])->middleware('track:account_info');
        Route::post('/', [ProfileController::class, 'setProfile']);
        Route::put('/', [ProfileController::class, 'updateProfile']);
        Route::post('/type', [ProfileController::class, 'setUserType'])->withoutMiddleware([EnsureRoleIsSelected::class]);

        Route::post('/real', [ProfileController::class, 'updateRealProfile']);
        Route::post('/legal', [ProfileController::class, 'updateLegalProfile']);
        // throttle: هر فراخوانی یک استعلامِ **پولیِ** پودیوم از سامانهٔ ثبت شرکت‌هاست
        // (services.company_inquiry) و هیچ کشی هم ندارد. بدون سقف، این endpoint یک
        // پروکسیِ نامحدود روی حساب مالکِ سرویس بود: یک اکانتِ رایگان می‌توانست فضای
        // شناسهٔ ملیِ اشخاص حقوقی را جاروب کند و رجیستری را با هزینهٔ ما آینه کند.
        // هم‌تراز با education/fetch که دقیقاً به همین دلیل throttle:5,1 دارد.
        Route::post('/legal/fetch', [ProfileController::class, 'fetchLegalData'])
            ->middleware('throttle:5,1');

        Route::get('/role-options', [ProfileController::class, 'getRoleOptions']);

        // توافق‌نامه‌ها — رسید پذیرش‌ها، فهرست نیازمند پذیرش مجدد، پذیرش فعال و PDF نسخه پذیرفته‌شده
        Route::prefix('agreements')->controller(AgreementController::class)->group(function () {
            Route::get('/', 'myAcceptances');
            Route::get('/pending', 'pending');
            Route::post('/{slug}/accept', 'accept')->where('slug', '[a-z_]+');
            Route::get('/{slug}/pdf', 'acceptedPdf')->where('slug', '[a-z_]+')->middleware('throttle:10,1');
        });
        Route::get('/user-types', [AuthController::class, 'types']);

        // Avatar (profile picture)
        Route::post('/avatar', [AvatarController::class, 'store']);
        Route::delete('/avatar', [AvatarController::class, 'destroy']);

        // Account security (password / mobile / email)
        Route::post('/verify-email', [AccountSecurityController::class, 'setEmail'])->middleware('throttle:3,1');
        Route::post('/check-email-token', [AccountSecurityController::class, 'checkToken']);
        // Email management (frontend contract) — throttle: هر فراخوانی یک ایمیل واقعی
        // می‌فرستد؛ بدون سقف، کاربر لاگین‌شده می‌توانست هر آدرسی را bomb کند.
        Route::post('/email', [AccountSecurityController::class, 'setEmail'])->middleware('throttle:3,1');              // add/change email + send verify link
        Route::post('/email/resend', [AccountSecurityController::class, 'resendEmailVerification'])->middleware('throttle:3,1');
        Route::delete('/email', [AccountSecurityController::class, 'deleteEmail']);
        Route::post('/password/otp', [AccountSecurityController::class, 'changePasswordFirstAttempt']);          // step 1: send code
        Route::post('/password/verify', [AccountSecurityController::class, 'verifyPasswordOtp'])->middleware('throttle:otp-verify'); // step 2: verify code (non-consuming)
        Route::post('/password', [AccountSecurityController::class, 'setPassword']);                            // step 3: set new password

        Route::prefix('change-mobile')->controller(AccountSecurityController::class)->group(function () {
            Route::get('/', 'updateMobile');
            // throttle:sms — هر فراخوانی یک استعلامِ *پولیِ* Podium (تطبیق کدملی/موبایل)
            // می‌زند و بعدش یک پیامک. بدون سقف، همان چیزی می‌شد که برای /education/fetch
            // بسته شد: یک پروکسیِ استعلامِ نامحدود روی سرویسِ پولی.
            Route::post('/request', 'requestChangeMobile')->middleware('throttle:sms');
            // throttle:otp-verify — کدِ ۶ رقمی brute-force پذیر است؛ هم‌ردیفِ
            // verify-code / password/verify / beneficiaries/confirm که همگی داشتند.
            Route::post('/confirm', 'confirmChangeMobile')->middleware('throttle:otp-verify');
        });

        // Notifications
        Route::prefix('messages')->controller(NotificationController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/unread-count', 'unreadCount');
            Route::patch('/read-all', 'markAllRead');
            Route::patch('/{userNotification}/read', 'markRead');
            Route::delete('/{userNotification}', 'destroy');
        });

        // Education (elite yar only)
        Route::prefix('education')->middleware('role.elite_yar')->controller(EduixController::class)->group(function () {
            Route::get('/', 'index')->middleware('track:education');
            // throttle: هر فراخوانی یک scrape واقعی روی سامانهٔ msrt.ir است؛
            // بدون سقف، endpoint به یک پروکسیِ استعلامِ نامحدود تبدیل می‌شد.
            Route::post('/fetch', 'fetch')->middleware('throttle:5,1');
            Route::delete('/{record}', 'destroy');
        });

        // Job experiences (elite yar only)
        Route::prefix('job-experiences')->middleware('role.elite_yar')->controller(JobExperienceController::class)->group(function () {
            Route::get('/', 'index')->middleware('track:job');
            Route::post('/', 'store');
            // PUT for text-only edits (JSON); POST (multipart) when replacing the attachment,
            // since PHP does not populate uploaded files on a raw PUT body.
            Route::match(['put', 'post'], '/{jobExperience}', 'update');
            Route::get('/{jobExperience}/file', 'download')->name('job-experience.file');
            Route::delete('/{jobExperience}', 'destroy');
        });

        // EliteYAR SMS invites (employer only) — نیمه‌ی دومِ جریانِ دعوتِ
        // الیت یارِ ثبت‌نام‌نکرده؛ قرینه‌ی گروهِ زیر.
        Route::prefix('elite-yar-invites')->middleware('role.employer')->controller(EliteYARInviteController::class)->group(function () {
            Route::get('/remaining', 'remaining');
            Route::post('/sms', 'sendSms')->middleware('throttle:3,1');
        });

        // Employer SMS invites (elite yar only)
        Route::prefix('employer-invites')->middleware('role.elite_yar')->controller(EmployerInviteController::class)->group(function () {
            Route::get('/remaining', 'remaining');
            Route::post('/sms', 'send')->middleware('throttle:3,1');
        });

        // ─────────────────────── Orders ────────────────────────────────────
        Route::prefix('orders')->group(function () {

            // List endpoints (must come BEFORE wildcard /{order})
            Route::controller(OrderController::class)->group(function () {

                // خرید برای خودم — defaults() به‌جای closure: روت‌های closure دار
                // php artisan route:cache را در deploy می‌شکنند (Uses Closure).
                Route::prefix('buy-myself')->group(function () {
                    Route::get('/current', 'myOrders')->defaults('statusGroup', 'current');
                    Route::get('/delivered', 'myOrders')->defaults('statusGroup', 'delivered');
                    Route::get('/cancelled', 'myOrders')->defaults('statusGroup', 'cancelled');
                });

                // خرید برای دیگران
                Route::prefix('buy-others')->group(function () {
                    Route::get('/current', 'otherOrders')->defaults('type', 'current');
                    Route::get('/delivered', 'otherOrders')->defaults('type', 'delivered');
                    Route::get('/cancelled', 'otherOrders')->defaults('type', 'cancelled');
                });

                Route::get('/', 'index')->middleware('track:orders');
            });

            // Financial operations on an order
            Route::controller(OrderPaymentController::class)->group(function () {
                Route::post('/{order}/pay-full', 'payFullOrder')->whereNumber('order')->middleware(['idempotency', 'throttle:10,1'])->name('api.user.orders.pay_full');
                Route::post('/{order}/upload-receipt', 'uploadReceipt')->whereNumber('order');
            });

            // Order status actions
            Route::controller(OrderActionController::class)->group(function () {
                Route::post('/{order}/cancel', 'cancel')->whereNumber('order');
                Route::post('/{order}/finalize-invoice', 'finalizeInvoice')->whereNumber('order');
                // Doc Q: eliteYAR submits Moadian invoice → releases commission hold
                Route::post('/{order}/elite-yar-moadian-invoice', 'submitEliteYARMoadianInvoice')->whereNumber('order');
            });

            // Invoice downloads
            //
            // فهرست از `OrderDocumentCatalog` می‌آید، نه دستی. نسخهٔ دستیِ قبلی
            // (`['initial', 'final', 'tax']`) با نقشهٔ مخاطبانِ کنترلر واگرا شده بود:
            // آن سه اسلاگ همگی buyer-only بودند و هشت سندِ سوپلایر — از جمله Doc K
            // «بارنامه/مجوز خروج» و Doc C «دستور بارگیری» — اصلاً با روت نمی‌خواندند.
            // نتیجه: سوپلایر و الیت‌یار هیچ سندی نمی‌توانستند دانلود کنند (۴۰۴/۴۰۳).
            // مجوز همچنان کارِ نقشهٔ مخاطبان است؛ این قید فقط تعیین می‌کند درخواست به
            // کنترلر برسد یا نه.
            Route::get('/{order}/invoice/{type}', [OrderInvoiceController::class, 'download'])
                ->whereNumber('order')
                ->whereIn('type', OrderDocumentCatalog::downloadableSlugs())
                ->name('api.user.orders.invoice');

            // Survey — پشت فلگ features.order_surveys، پیش‌فرض خاموش
            // (این قابلیت در محصولِ فعلی وجود ندارد؛ config/features.php)
            if (config('features.order_surveys')) {
                Route::post('/{order}/survey', [OrderSurveyController::class, 'store'])->whereNumber('order');
            }

            // Supplier operations
            Route::controller(SupplierOrderController::class)->group(function () {
                Route::post('/{order}/espadana', 'issueEspadanaCommand')->whereNumber('order');
                Route::post('/{order}/receipt', 'attachPaymentReceipt')->whereNumber('order');
                // Doc O: supplier submits Moadian invoice → releases supplier VAT hold
                Route::post('/{order}/supplier-moadian-invoice', 'submitSupplierMoadianInvoice')->whereNumber('order');
            });

            // Detail view (MUST be the last route in the orders group — wildcard)
            Route::get('/{order}', [OrderController::class, 'show'])->whereNumber('order');
        });

        // ─────────────────────── Financial ─────────────────────────────────
        Route::prefix('financial')->group(function () {

            // Wallet
            Route::controller(WalletController::class)->prefix('wallet')->group(function () {
                Route::get('/', 'getWallet');
                Route::get('/transactions', 'getTransactions');
                Route::get('/deposit-account', 'getDepositAccount'); // شناسه واریز (PAYA/SATNA direct deposit)
                Route::post('/deposit', 'requestDeposit')->middleware('throttle:financial')->name('wallet.deposit');
                Route::post('/withdraw', 'requestWithdrawal')->middleware(['idempotency', 'throttle:financial']);
            });

            // Receipts (deposit slips)
            Route::controller(WalletController::class)->prefix('receipts')->group(function () {
                Route::get('/', 'getReceipts');
                Route::post('/', 'storeReceipt')->middleware('idempotency');
                Route::delete('/{transaction}', 'deleteReceipt')->whereNumber('transaction');
                Route::get('/transactions/{transaction}/download', 'downloadReceipt')
                    ->whereNumber('transaction')
                    ->name('api.financial.download');
            });

            // Bank accounts
            Route::apiResource('accounts', UserBankAccountController::class)->except(['show', 'update']);
        });

        // Addresses
        Route::prefix('addresses')->controller(AddressController::class)->group(function () {
            Route::get('/', 'index')->middleware('track:addresses');
            Route::post('/', 'store');
            Route::put('/{address}', 'update');
            Route::delete('/{address}', 'destroy');
            Route::post('/{address}/default', 'setDefault');
        });

        // Wishlists
        Route::prefix('wishlists')->controller(WishlistController::class)->group(function () {
            Route::get('/', 'index')->middleware('track:my_items');
            Route::post('/', 'store');
            Route::get('/{wishlist}', 'show');
            Route::put('/{wishlist}', 'update');
            Route::delete('/{wishlist}', 'destroy');
            Route::post('/toggle-variant', 'toggleVariant');
            Route::post('/{wishlist}/variants/{variant}', 'addVariant');
            Route::delete('/{wishlist}/variants/{variant}', 'removeVariant');
        });

        // Stock subscriptions
        Route::prefix('stock-subscriptions')->controller(StockSubscriptionController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/toggle', 'toggle');
        });

        Route::prefix('my-items')->controller(MyItemsController::class)->group(function () {
            Route::get('/badges', 'badges');
            // پشت فلگ features.order_surveys — پیش‌فرض خاموش (config/features.php)
            if (config('features.order_surveys')) {
                Route::get('/surveys', 'surveys');
            }
        });

        // EliteYARs ↔ Employers
        Route::prefix('elite-yars')->controller(EliteYARRelationController::class)->group(function () {
            Route::middleware('role.employer')->group(function () {
                Route::get('/', 'listMyEliteYARs')->middleware('track:relations');
                Route::post('/invite', 'inviteEliteYAR');
                Route::delete('/{eliteYAR}', 'removeEliteYAR');
            });
            Route::middleware('role.elite_yar')->group(function () {
                Route::get('/employers', 'listMyEmployers');
                Route::get('/invitations', 'listMyInvitations');
                Route::get('/invitations/rejection-reasons', 'getRejectionReasons');
                Route::post('/invitations/{employer}/accept', 'acceptInvitation');
                Route::post('/invitations/{employer}/reject', 'rejectInvitation');
                Route::get('/registered-clients', 'listRegisteredClients');
            });
        });

        // Role-specific profile completion
        Route::post('/engineer', [EngineerController::class, 'store']);
        Route::post('/contractor', [ContractorController::class, 'store']);
        Route::post('/employer', [EmployerController::class, 'store']);
        Route::delete('/employer', [EmployerController::class, 'destroy']);
        Route::prefix('supplier')->group(function () {
            // Open to any authenticated user applying to become a supplier (pre-approval)
            //
            // GET عمداً بیرونِ `role.supplier` است: نقش فقط با تأییدِ ادمین وصل می‌شود،
            // پس متقاضیِ در انتظار و کاربرِ ردشده (که نقشش جدا شده) هم باید بتوانند
            // وضعیتِ خودشان را بخوانند. کنترلر خودش روی Auth::id() قفل است.
            Route::get('/', [SupplierController::class, 'show']);
            Route::post('/', [SupplierController::class, 'store']);
            Route::delete('/', [SupplierController::class, 'destroy']);
            Route::get('/activity-categories', [SupplierController::class, 'getCategories']);
            Route::get('/activityCategory-getId', [SupplierController::class, 'getActivityCategoryId']);

            // Self-service panel — approved suppliers only (role.supplier)
            Route::middleware('role.supplier')->group(function () {
                // اعلامِ اعتبارِ قیمت‌ها — تا وقتی ثبت نشود، کالاهای این
                // تأمین‌کننده قابل سفارش نیستند (بند ۳.۲).
                Route::put('/reserve-time', [SupplierController::class, 'updateReserveTime']);

                Route::get('/orders', [SupplierOrderController::class, 'index']);
                Route::get('/orders/pending-debts', [SupplierOrderController::class, 'pendingDebts']);
                Route::get('/orders/pending-moadian', [SupplierOrderController::class, 'pendingMoadian']);
                Route::get('/dashboard', [SupplierOrderController::class, 'dashboard']);

                // Factories — CRUD scoped to factories.supplier_user_id = خودم
                Route::controller(SupplierFactoryController::class)->prefix('factories')->group(function () {
                    Route::get('/', 'index');
                    Route::get('/{factory}', 'show');
                    Route::post('/', 'store');
                    Route::put('/{factory}', 'update');
                    Route::post('/{factory}', 'update'); // multipart logo replace
                    Route::delete('/{factory}', 'destroy');
                    Route::post('/{factory}/toggle', 'toggle');
                });

                // Factory Locations — CRUD انبارها/مبادی، فقط زیر کارخانه‌های خودم
                Route::controller(SupplierFactoryLocationController::class)->prefix('factory-locations')->group(function () {
                    Route::get('/', 'index');
                    Route::post('/', 'store');
                    Route::put('/{factoryLocation}', 'update');
                    Route::delete('/{factoryLocation}', 'destroy');
                    Route::post('/{factoryLocation}/toggle', 'toggle');
                });

                // Product Prices — قیمت واریانت‌های موجود، فقط در کارخانه/انبار خودم
                Route::controller(SupplierProductPriceController::class)->prefix('prices')->group(function () {
                    Route::get('/', 'index');
                    Route::post('/', 'store');
                    Route::put('/{price}', 'update');
                    Route::delete('/{price}', 'destroy');
                });

                // Stocks — موجودی به‌ازای کارخانه/انبارِ خودم.
                // پیش از جدولِ factory_stocks این پنل ممکن نبود: موجودی یک ستونِ
                // سراسری روی هر واریانت بود، پس نوشتنِ تأمین‌کنندهٔ A موجودیِ B را
                // صفر می‌کرد.
                Route::controller(SupplierStockController::class)->prefix('stocks')->group(function () {
                    Route::get('/', 'index');
                    Route::post('/', 'store');   // upsert روی سه‌تاییِ (واریانت، کارخانه، انبار)
                    Route::put('/{stock}', 'update');
                    Route::delete('/{stock}', 'destroy');
                });

                // Shipping Rates — نرخ‌های حمل، فقط با مبدأ = انبارهای خودم
                Route::controller(SupplierShippingRateController::class)->prefix('shipping-rates')->group(function () {
                    Route::get('/', 'index');
                    Route::post('/', 'store');
                    Route::put('/{shippingRate}', 'update');
                    Route::delete('/{shippingRate}', 'destroy');
                    Route::post('/{shippingRate}/toggle', 'toggle');
                });
            });
        });
    });

    // ──────────────────── Top-level authenticated misc ────────────────────
    Route::post('/listophers', [ListopherController::class, 'store']);

    // Beneficiaries — throttle:sms روی start (پیامک به شخص ثالث می‌فرستد) و
    // throttle:otp-verify روی confirm (کد ۶ رقمی brute-force پذیر است)
    Route::prefix('beneficiaries')->group(function () {
        Route::post('/start-verification', [BeneficiaryController::class, 'startVerification'])
            ->middleware('throttle:sms');
        Route::post('/confirm-verification', [BeneficiaryController::class, 'confirmVerification'])
            ->middleware('throttle:otp-verify');
    });

    // Current user info
    Route::get('/user', [AuthController::class, 'me']);
});
