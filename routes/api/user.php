<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Data\AnalyticsController;
use App\Http\Controllers\Api\Data\InventoryController;
use App\Http\Controllers\Api\ListopherController;
use App\Http\Controllers\Api\User\AccountSecurityController;
use App\Http\Controllers\Api\User\AddressController;
use App\Http\Controllers\Api\User\BeneficiaryController;
use App\Http\Controllers\Api\User\CartController;
use App\Http\Controllers\Api\User\CheckoutController;
use App\Http\Controllers\Api\User\ConsultantRelationController;
use App\Http\Controllers\Api\User\ContractorController;
use App\Http\Controllers\Api\User\DashboardController;
use App\Http\Controllers\Api\User\EduixController;
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
use App\Http\Controllers\Api\User\ProfileController;
use App\Http\Controllers\Api\User\ProfileSearchController;
use App\Http\Controllers\Api\User\StockSubscriptionController;
use App\Http\Controllers\Api\User\SupplierController;
use App\Http\Controllers\Api\User\SupplierOrderController;
use App\Http\Controllers\Api\User\UserBankAccountController;
use App\Http\Controllers\Api\User\WalletController;
use App\Http\Controllers\Api\User\WishlistController;
use App\Http\Controllers\Payment\WalletPaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Middleware\EnsureRoleIsSelected;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authenticated User Routes
|--------------------------------------------------------------------------
| همه روتهای نیازمند لاگین کاربر. هر group بسته به نیازش middleware اضافه
| دارد (attach.price.category, role.expert, idempotency, throttle, …).
*/

// ── Wallet payment + misc auth ────────────────────────────────────────────
Route::middleware(['auth:sanctum'])->group(function () {

    // درگاه پرداخت کیف پول
    Route::post('payment/wallet', [WalletPaymentController::class, 'pay'])
        ->middleware('throttle:financial')
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

// ── Cart — مهمان هم می‌تواند سبد داشته باشد ─────────────────────────────
Route::middleware(['optional.sanctum', 'attach.price.category'])->group(function () {
    Route::prefix('cart')->controller(CartController::class)->group(function () {
        Route::get('/', 'getCart');
        Route::post('/items', 'addToCart');
        Route::patch('/items/{item}', 'updateCartItem');
        Route::delete('/items/{item}', 'removeCartItem');
        Route::patch('/filters', 'updateCartFilters');
        Route::patch('/customer', 'updateCustomerType');
        // استعلام هزینه حمل پیش از checkout — نیازمند آدرس کاربر (auth الزامی)
        Route::post('/shipping-quote', 'shippingQuote')->middleware('auth:sanctum');
    });
});

// ── Checkout — نیاز به احراز هویت دارد ───────────────────────────────────
Route::middleware(['auth:sanctum', 'attach.price.category'])->group(function () {
    Route::controller(CheckoutController::class)->prefix('checkout')->group(function () {
        Route::post('/', 'placeOrder')->middleware(EnsureRoleIsSelected::class);
        Route::get('/payment/{order}', 'paymentInfo');
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
        Route::get('/badge', [\App\Http\Controllers\Api\User\BadgeController::class, 'show']);

        // Basic profile data
        Route::get('/', [ProfileController::class, 'getProfile'])->middleware('track:account_info');
        Route::post('/', [ProfileController::class, 'setProfile']);
        Route::put('/', [ProfileController::class, 'updateProfile']);
        Route::post('/type', [ProfileController::class, 'setUserType'])->withoutMiddleware([EnsureRoleIsSelected::class]);

        Route::post('/real', [ProfileController::class, 'updateRealProfile']);
        Route::post('/legal', [ProfileController::class, 'updateLegalProfile']);
        Route::post('/legal/fetch', [ProfileController::class, 'fetchLegalData']);

        Route::get('/role-options', [ProfileController::class, 'getRoleOptions']);
        Route::get('/user-types', [AuthController::class, 'types']);

        // Avatar (profile picture)
        Route::post('/avatar', [\App\Http\Controllers\Api\User\AvatarController::class, 'store']);
        Route::delete('/avatar', [\App\Http\Controllers\Api\User\AvatarController::class, 'destroy']);

        // Account security (password / mobile / email)
        Route::post('/verify-email', [AccountSecurityController::class, 'setEmail']);
        Route::post('/check-email-token', [AccountSecurityController::class, 'checkToken']);
        // Email management (frontend contract)
        Route::post('/email', [AccountSecurityController::class, 'setEmail']);              // add/change email + send verify link
        Route::post('/email/resend', [AccountSecurityController::class, 'resendEmailVerification']);
        Route::delete('/email', [AccountSecurityController::class, 'deleteEmail']);
        Route::post('/password/otp', [AccountSecurityController::class, 'changePasswordFirstAttempt']);          // step 1: send code
        Route::post('/password/verify', [AccountSecurityController::class, 'verifyPasswordOtp'])->middleware('throttle:otp-verify'); // step 2: verify code (non-consuming)
        Route::post('/password', [AccountSecurityController::class, 'setPassword']);                            // step 3: set new password

        Route::prefix('change-mobile')->controller(AccountSecurityController::class)->group(function () {
            Route::get('/', 'updateMobile');
            Route::post('/request', 'requestChangeMobile');
            Route::post('/confirm', 'confirmChangeMobile');
        });

        // Notifications
        Route::prefix('messages')->controller(NotificationController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/unread-count', 'unreadCount');
            Route::patch('/read-all', 'markAllRead');
            Route::patch('/{userNotification}/read', 'markRead');
            Route::delete('/{userNotification}', 'destroy');
        });

        // Education (expert only)
        Route::prefix('education')->middleware('role.expert')->controller(EduixController::class)->group(function () {
            Route::get('/', 'index')->middleware('track:education');
            Route::post('/fetch', 'fetch');
            Route::delete('/{record}', 'destroy');
        });

        // Job experiences (expert only)
        Route::prefix('job-experiences')->middleware('role.expert')->controller(JobExperienceController::class)->group(function () {
            Route::get('/', 'index')->middleware('track:job');
            Route::post('/', 'store');
            // PUT for text-only edits (JSON); POST (multipart) when replacing the attachment,
            // since PHP does not populate uploaded files on a raw PUT body.
            Route::match(['put', 'post'], '/{jobExperience}', 'update');
            Route::get('/{jobExperience}/file', 'download')->name('job-experience.file');
            Route::delete('/{jobExperience}', 'destroy');
        });

        // Employer SMS invites (expert/advisor only)
        Route::prefix('employer-invites')->middleware('role.expert')->controller(EmployerInviteController::class)->group(function () {
            Route::get('/remaining', 'remaining');
            Route::post('/sms', 'send')->middleware('throttle:3,1');
        });

        // ─────────────────────── Orders ────────────────────────────────────
        Route::prefix('orders')->group(function () {

            // List endpoints (must come BEFORE wildcard /{order})
            Route::controller(OrderController::class)->group(function () {

                // خرید برای خودم
                Route::prefix('buy-myself')->group(function () {
                    Route::get('/current', fn (Request $request) => app(OrderController::class)->myOrders($request, 'current'));
                    Route::get('/delivered', fn (Request $request) => app(OrderController::class)->myOrders($request, 'delivered'));
                    Route::get('/cancelled', fn (Request $request) => app(OrderController::class)->myOrders($request, 'cancelled'));
                });

                // خرید برای دیگران
                Route::prefix('buy-others')->group(function () {
                    Route::get('/current', fn (Request $request) => app(OrderController::class)->otherOrders($request, 'current'));
                    Route::get('/delivered', fn (Request $request) => app(OrderController::class)->otherOrders($request, 'delivered'));
                    Route::get('/cancelled', fn (Request $request) => app(OrderController::class)->otherOrders($request, 'cancelled'));
                });

                Route::get('/', 'index')->middleware('track:orders');
            });

            // Financial operations on an order
            Route::controller(OrderPaymentController::class)->group(function () {
                Route::post('/{order}/pay-hold-fee', 'payHoldFee')->whereNumber('order')->middleware(['idempotency', 'throttle:10,1']);
                Route::post('/{order}/pay-full', 'payFullOrder')->whereNumber('order')->middleware(['idempotency', 'throttle:10,1'])->name('api.user.orders.pay_full');
                Route::post('/{order}/upload-receipt', 'uploadReceipt')->whereNumber('order');
            });

            // Order status actions
            Route::controller(OrderActionController::class)->group(function () {
                Route::post('/{order}/cancel', 'cancel')->whereNumber('order');
                Route::post('/{order}/finalize-invoice', 'finalizeInvoice')->whereNumber('order');
                // Doc Q: consultant submits Moadian invoice → releases commission hold
                Route::post('/{order}/consultant-moadian-invoice', 'submitConsultantMoadianInvoice')->whereNumber('order');
            });

            // Invoice downloads
            Route::get('/{order}/invoice/{type}', [OrderInvoiceController::class, 'download'])
                ->whereNumber('order')
                ->whereIn('type', ['initial', 'final', 'tax'])
                ->name('api.user.orders.invoice');

            // Survey
            Route::post('/{order}/survey', [OrderSurveyController::class, 'store'])->whereNumber('order');

            // Supplier operations
            Route::controller(SupplierOrderController::class)->group(function () {
                Route::post('/{order}/prepare', 'markAsPrepared')->whereNumber('order'); // تأیید (شروع آماده‌سازی)
                Route::post('/{order}/reject', 'rejectOrder')->whereNumber('order');      // رد سفارش (+ refund خریدار)
                Route::post('/{order}/waybill', 'addWaybill')->whereNumber('order');
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
                Route::delete('/{transaction}', 'deleteReceipt');
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
            Route::get('/surveys', 'surveys');
        });

        // Consultants ↔ Employers
        Route::prefix('consultants')->controller(ConsultantRelationController::class)->group(function () {
            Route::middleware('role.employer')->group(function () {
                Route::get('/', 'listMyConsultants')->middleware('track:relations');
                Route::post('/invite', 'inviteConsultant');
                Route::delete('/{consultant}', 'removeConsultant');
            });
            Route::middleware('role.expert')->group(function () {
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
            Route::post('/', [SupplierController::class, 'store']);
            Route::delete('/', [SupplierController::class, 'destroy']);
            Route::get('/activity-categories', [SupplierController::class, 'getCategories']);
            Route::get('/activityCategory-getId', [SupplierController::class, 'getActivityCategoryId']);
        });
    });

    // ──────────────────── Top-level authenticated misc ────────────────────
    Route::post('/listophers', [ListopherController::class, 'store']);

    // Beneficiaries
    Route::prefix('beneficiaries')->group(function () {
        Route::post('/start-verification', [BeneficiaryController::class, 'startVerification']);
        Route::post('/confirm-verification', [BeneficiaryController::class, 'confirmVerification']);
    });

    // Current user info
    Route::get('/user', [AuthController::class, 'me']);
});
