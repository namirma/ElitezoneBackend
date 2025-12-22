<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AddressController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ConsultantRelationController;
use App\Http\Controllers\CustomerTypeController;
use App\Http\Controllers\EduixController;
use App\Http\Controllers\FactoryController;
use App\Http\Controllers\IdentityInquirySc2Controller;
use App\Http\Controllers\JobExperienceController;
use App\Http\Controllers\MobileNidMatchController;
use App\Http\Controllers\Api\User\OrderActionController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderSurveyController;
use App\Http\Controllers\ParameterDetectionController;
use App\Http\Controllers\PaymentController; // Added
use App\Http\Controllers\PodiumBalanceController;
use App\Http\Controllers\PodiumBatchSettlementController;
use App\Http\Controllers\PodiumSettlementController;
use App\Http\Controllers\PodiumSettlementInquiryController;
use App\Http\Controllers\PriceFilterController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseParameterController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StockSubscriptionController;
use App\Http\Controllers\UserBankAccountController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WishlistController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ListopherController;
use App\Http\Controllers\Api\QuestionController;
use App\Http\Controllers\Api\User\ContractorController;
use App\Http\Controllers\Api\User\DashboardController;
use App\Http\Controllers\Api\User\EmployerController;
use App\Http\Controllers\Api\User\EngineerController;
use App\Http\Controllers\Api\User\ProfileController;
use App\Http\Controllers\Api\User\SupplierController;
use App\Http\Controllers\Api\DebugController;
use Illuminate\Http\Request;

// Admin Controllers
use App\Http\Controllers\Api\Admin\AdminActivityLogController;
use App\Http\Controllers\Api\Admin\AdminFinancialController;
use App\Http\Controllers\Api\Admin\AdminOrderController;
use App\Http\Controllers\Api\Admin\AdminSearchController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AdminWalletController;
use App\Http\Controllers\Api\Data\AnalyticsController;
use App\Http\Controllers\Api\User\OrderInvoiceController;
use App\Http\Controllers\Api\User\OrderPaymentController;
use App\Http\Controllers\Api\User\SupplierOrderController;
// Middleware
use App\Http\Middleware\EnsureRoleIsSelected;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// --- 1. روت‌های عمومی (Public) ---
Route::post('/payment/result', [PaymentController::class, 'result']);
Route::any('/payment/callback', [PaymentController::class, 'callback'])->name('payment.callback');

Route::get('/search', [SearchController::class, 'search']);
Route::post('/new-question', [QuestionController::class, 'submitQuestion']);

Route::get('/test-cache', [DebugController::class, 'testCache']);
Route::get('kavir-products', [DebugController::class, 'kavirProducts']);

Route::prefix('data')->middleware(['auth:sanctum'])->group(function () {

    Route::prefix('analytics')->group(function () {

        Route::get('/dashboard', [AnalyticsController::class, 'dashboard']);

        Route::get('/revenue', [AnalyticsController::class, 'revenue']);
    });

});

// --- احراز هویت ---
Route::controller(AuthController::class)->prefix('authenticate')->group(function () {
    Route::post('/', 'register')->middleware('throttle:sms');
    Route::post('/check-user', 'checkUserAndSendCode');
    Route::post('/verify-code', 'verifyCode');
    Route::post('/password/check', 'checkPassword');
    Route::post('/forget-password', 'forgetPassword')->middleware('throttle:sms');
    Route::post('/change-password', 'changePassword');
    Route::post('/send-code', 'sendVerificationCode')->middleware('throttle:sms');
    Route::middleware('auth:sanctum')->post('/logout', 'logout');
});

// --- محصولات و دسته‌بندی‌ها ---
Route::prefix('products')->middleware(['auth:sanctum', 'attach.price.category'])->group(function () {
    Route::get('categories', [ProductCategoryController::class, 'index']);
    Route::get('/', [ProductController::class, 'index']);
    Route::get('/{product:cuid}', [ProductController::class, 'show']);
    Route::get('/{product:cuid}/variants', [ProductController::class, 'variants']);
    Route::get('/{product:cuid}/filters', [ProductController::class, 'filters']);
    Route::post('/variant-price',   [ProductController::class, 'getVariantPrice']);
    Route::post('/calculate-price', [ProductController::class, 'calculateOrderPrice']);
});

// --- اطلاعات پایه و عمومی ---
Route::prefix('factories')->group(function () {
    Route::get('/', [FactoryController::class, 'listFactories']);
    Route::get('/{id}/locations', [FactoryController::class, 'listLocations']);
});
Route::prefix('customer-types')->group(function () {
    Route::get('/', [CustomerTypeController::class, 'index']);
    Route::get('/badges', [CustomerTypeController::class, 'getBadges']);
});
Route::prefix('price-filters')->group(function () {
    Route::get('/categories', [PriceFilterController::class, 'getCategories']);
    Route::get('/types', [PriceFilterController::class, 'getFilterTypes']);
});
Route::prefix('purchase-parameters')->group(function () {
    Route::get('/groups', [PurchaseParameterController::class, 'groups']);
    Route::get('/user', [PurchaseParameterController::class, 'userParameters']);
    Route::post('/user', [PurchaseParameterController::class, 'store']);
    Route::post('/detect-price-filters', [ParameterDetectionController::class, 'detectPriceFilters']);
});

// --- سبد خرید و چک‌اوت (محافظت شده) ---
Route::middleware(['auth:sanctum', 'attach.price.category'])->group(function () {
    Route::prefix('cart')->controller(CartController::class)->group(function () {
        Route::get('/', 'getCart');
        Route::post('/items', 'addToCart');
        Route::patch('/items/{item}', 'updateCartItem');
        Route::delete('/items/{item}', 'removeCartItem');
        Route::patch('/filters', 'updateCartFilters');
        Route::patch('/customer', 'updateCustomerType');
    });

    Route::controller(CheckoutController::class)->prefix('checkout')->group(function () {
        Route::post('/', 'placeOrder')->middleware(EnsureRoleIsSelected::class);
        Route::get('/payment/{order}', 'paymentInfo');
    });
});

// --- پادیوم (سرویس‌ها) ---
Route::prefix('v1/podium')->group(function () {
    Route::post('/settlements/single', [PodiumSettlementController::class, 'single']);
    Route::get('/balances', [PodiumBalanceController::class, 'index']);
    Route::post('/settlements/batch', [PodiumBatchSettlementController::class, 'store']);
    Route::get('/settlements/inquiry', [PodiumSettlementInquiryController::class, 'index']);
    Route::post('/identity-inquiry-sc2', IdentityInquirySc2Controller::class);
    Route::post('mobile-nid-match', [MobileNidMatchController::class, 'store']);
});

// --- پنل ادمین ---
Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
    Route::get('/activities', [AdminActivityLogController::class, 'index']);
    Route::controller(AdminSearchController::class)->prefix('search')->group(function () {
        Route::get('/users', 'searchUsers');
        Route::get('/transactions', 'searchTransactions');
    });
    Route::controller(AdminFinancialController::class)->prefix('financial')->group(function () {
        Route::get('/company/invoices', 'getCompanyInvoices');
        Route::get('/company/expert-shares', 'getExpertShares');
        Route::get('/supplier/documents', 'getSupplierDocuments');
        Route::get('/expert/documents', 'getExpertDocuments');
    });
    Route::controller(AdminUserController::class)->prefix('users')->group(function () {
        Route::get('/', 'index');
        Route::get('/{user}', 'show');
        Route::post('/{user}/toggle-status', 'toggleStatus');
        Route::post('/{user}/note', 'addNote');
    });
    Route::controller(AdminOrderController::class)->prefix('orders')->group(function () {
        Route::get('/', 'index');
        Route::get('/{order}/history', 'history');
        Route::get('/{order}', 'show');
        Route::post('/{order}/status', 'changeStatus');
    });
    Route::controller(AdminWalletController::class)->prefix('wallet')->group(function () {
        Route::get('/transactions', 'index');
        Route::post('/transactions/{transaction}/status', 'changeStatus');
    });
});

// --- روت‌های نیازمند لاگین ---
Route::middleware(['auth:sanctum'])->group(function () {

    // 2. استان‌ها (Refactored)
    Route::prefix('province')->controller(AddressController::class)->group(function () {
        Route::get('/', 'getProvinces');
        Route::get('/cities/{provinceID?}', 'getCities');
    });

    // 3. پروفایل و زیرمجموعه‌ها
    Route::prefix('profile')->group(function () {

        Route::get('/dashboard', [DashboardController::class, 'index']);

        // اطلاعات پایه
        Route::get('/', [ProfileController::class, 'getProfile'])->middleware('track:account_info');
        Route::post('/', [ProfileController::class, 'setProfile']);
        Route::put('/', [ProfileController::class, 'updateProfile']);
        Route::post('/type', [ProfileController::class, 'setUserType'])->withoutMiddleware([EnsureRoleIsSelected::class]);

        Route::post('/real', [ProfileController::class, 'updateRealProfile']);
        Route::post('/legal', [ProfileController::class, 'updateLegalProfile']);
        Route::post('/legal/fetch', [ProfileController::class, 'fetchLegalData']);

        Route::get('/role-options', [ProfileController::class, 'getRoleOptions']);
        Route::get('/user-types', [AuthController::class, 'types']);

        Route::post('/verify-email', [ProfileController::class, 'setEmail']);
        Route::post('/check-email-token', [ProfileController::class, 'checkToken']);
        Route::post('/password', [ProfileController::class, 'setPassword']);

        Route::prefix('change-mobile')->controller(ProfileController::class)->group(function () {
            Route::get('/', 'updateMobile');
            Route::post('/request', 'requestChangeMobile');
            Route::post('/confirm', 'confirmChangeMobile');
        });

        // پیغام‌ها
        Route::get('/messages', [ProfileController::class, 'getMessages'])->middleware('track:messages');
        Route::get('/messages/read-all', [ProfileController::class, 'updateAllMessageStatus']);
        Route::get('/messages/{userNotification}', [ProfileController::class, 'updateMessageStatus']);

        // تحصیلات
        Route::prefix('education')->middleware('role.expert')->controller(EduixController::class)->group(function () {
            Route::get('/', 'index')->middleware('track:education');
            Route::post('/fetch', 'fetch');
            Route::delete('/{record}', 'destroy');
        });

        // سوابق شغلی
        Route::prefix('job-experiences')->middleware('role.expert')->controller(JobExperienceController::class)->group(function () {
            Route::get('/', 'index')->middleware('track:job');
            Route::post('/', 'store');
            Route::delete('/{jobExperience}', 'destroy');
        });

        // سفارش‌ها
        Route::prefix('orders')->group(function () {

            // 1. نمایش و لیست‌ها (OrderController)
            Route::controller(OrderController::class)->group(function () {
                Route::get('/', 'index')->middleware('track:orders');
                Route::get('/{order}', 'show')->whereNumber('order');

                Route::prefix('buy-myself')->group(function () {
                    Route::get('/current', fn(Request $request) => app(OrderController::class)->myOrders($request, 'current'));
                    Route::get('/delivered', fn(Request $request) => app(OrderController::class)->myOrders($request, 'delivered'));
                    Route::get('/cancelled', fn(Request $request) => app(OrderController::class)->myOrders($request, 'cancelled'));
                });

                Route::prefix('buy-others')->group(function () {
                    Route::get('/current', fn(Request $request) => app(OrderController::class)->otherOrders($request, 'current'));
                    Route::get('/delivered', fn(Request $request) => app(OrderController::class)->otherOrders($request, 'delivered'));
                    Route::get('/cancelled', fn(Request $request) => app(OrderController::class)->otherOrders($request, 'cancelled'));
                });
            });

            // 2. عملیات مالی (OrderPaymentController)
            Route::controller(OrderPaymentController::class)->group(function () {
                Route::post('/{order}/pay-hold-fee', 'payHoldFee');
                Route::post('/{order}/pay-full', 'payFullOrder');
                Route::post('/{order}/upload-receipt', 'uploadReceipt');
            });

            // 3. عملیات اکشن (OrderActionController)
            Route::controller(OrderActionController::class)->group(function () {
                Route::post('/{order}/cancel', 'cancel');
                Route::post('/{order}/finalize-invoice', 'finalizeInvoice');
            });

            // 4. فاکتور (OrderInvoiceController)
            Route::get('/{order}/invoice/{type}', [OrderInvoiceController::class, 'download'])
                ->whereIn('type', ['initial', 'final', 'tax']);

            // 5. نظرسنجی
            Route::post('/{order}/survey', [OrderSurveyController::class, 'store']);

            // 6. تامین‌کننده (SupplierOrderController)
            Route::controller(SupplierOrderController::class)->group(function () {
                Route::post('/{order}/prepare', 'markAsPrepared');
                Route::post('/{order}/waybill', 'addWaybill');
                Route::post('/{order}/espadana', 'issueEspadanaCommand');
                Route::post('/{order}/receipt', 'attachPaymentReceipt');
            });
        });

        // مدیریت مالی
        Route::prefix('financial')->group(function () {

            // گروه Wallet
            Route::controller(WalletController::class)->prefix('wallet')->group(function () {
                Route::get('/', 'getWallet');
                Route::get('/transactions', 'getTransactions');
                Route::post('/deposit', 'requestDeposit');
                Route::post('/withdraw', 'requestWithdrawal')->middleware('idempotency');
            });

            // گروه Receipts (فیش‌ها)
            Route::controller(WalletController::class)->prefix('receipts')->group(function () {
                Route::get('/', 'getReceipts');
                Route::post('/', 'storeReceipt')->middleware('idempotency');
                Route::delete('/{transaction}', 'deleteReceipt');
                Route::get('/transactions/{transaction}/download', 'downloadReceipt')
                    ->whereNumber('transaction')
                    ->name('api.financial.download');
            });

            // حساب‌های بانکی
            Route::apiResource('accounts', UserBankAccountController::class)->except(['show', 'update']);
        });

        // آدرس‌ها
        Route::prefix('addresses')->controller(AddressController::class)->group(function () {
            Route::get('/', 'index')->middleware('track:addresses');
            Route::post('/', 'store');
            Route::put('/{address}', 'update');
            Route::delete('/{address}', 'destroy');
            Route::post('/{address}/default', 'setDefault');
        });

        // آیتم‌های من (Wishlists)
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

        // اشتراک موجودی
        Route::prefix('stock-subscriptions')->controller(StockSubscriptionController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/toggle', 'toggle');
        });

        // مشاوران / کارفرمایان
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
            });
        });

        // تکمیل پروفایل نقش‌های خاص
        Route::post('/engineer', [EngineerController::class, 'store']);
        Route::post('/contractor', [ContractorController::class, 'store']);
        Route::post('/employer', [EmployerController::class, 'store']);
        Route::prefix('supplier')->group(function () {
            Route::post('/', [SupplierController::class, 'store']);
            Route::get('/activity-categories', [SupplierController::class, 'getCategories']);
            // Refactored here:
            Route::get('/activityCategory-getId', [SupplierController::class, 'getActivityCategoryId']);
        });
    });

    Route::post('/listophers', [ListopherController::class, 'store']);

    // ذی‌نفعان
    Route::group(['prefix' => 'beneficiaries'], function () {
        Route::post('/start-verification', [\App\Http\Controllers\BeneficiaryController::class, 'startVerification']);
        Route::post('/confirm-verification', [\App\Http\Controllers\BeneficiaryController::class, 'confirmVerification']);
    });

    // Refactored User Route
    Route::get('/user', [AuthController::class, 'me']);
});
