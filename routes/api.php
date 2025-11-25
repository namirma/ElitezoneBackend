<?php

use App\Http\Controllers\Api\Admin\AdminFinancialController;
use App\Http\Controllers\Api\Admin\AdminOrderController;
use App\Http\Controllers\Api\Admin\AdminSearchController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\Api\ListopherController;
use App\Http\Controllers\IdentityInquirySc2Controller;
use App\Http\Controllers\Api\QuestionController;
use App\Http\Controllers\Api\User\ContractorController;
use App\Http\Controllers\Api\User\DashboardController;
use App\Http\Controllers\Api\User\EmployerController;
use App\Http\Controllers\Api\User\EngineerController;
use App\Http\Controllers\Api\User\ProfileController;
use App\Http\Controllers\Api\User\SupplierController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\ConsultantRelationController;
use App\Http\Controllers\CustomerTypeController;
use App\Http\Controllers\EduixController;
use App\Http\Controllers\FactoryController;
use App\Http\Controllers\JobExperienceController;
use App\Http\Controllers\MobileNidMatchController;
use App\Http\Controllers\OrderActionController;
use App\Http\Controllers\ParameterDetectionController;
use App\Http\Controllers\PodiumBalanceController;
use App\Http\Controllers\PodiumBatchSettlementController;
use App\Http\Controllers\PriceFilterController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseParameterController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\WishlistController;
use App\Models\User;
use App\Services\Gateways\GatewayFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PodiumSettlementController;
use App\Http\Controllers\PodiumSettlementInquiryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderSurveyController;
use App\Http\Controllers\StockSubscriptionController;
use App\Http\Controllers\UserBankAccountController;
use App\Http\Controllers\WalletController;
use App\Http\Middleware\EnsureRoleIsSelected;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::prefix('v1/podium')->group(function () {
    Route::post('/settlements/single', [PodiumSettlementController::class, 'single']);
    Route::get('/balances', [PodiumBalanceController::class, 'index']);
    Route::post('/settlements/batch', [PodiumBatchSettlementController::class, 'store']);
    Route::get('/settlements/inquiry', [PodiumSettlementInquiryController::class, 'index']);
    Route::post('/identity-inquiry-sc2', IdentityInquirySc2Controller::class);
    Route::post('mobile-nid-match', [MobileNidMatchController::class, 'store']);
});

Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {

    // 1. جستجو (Search)
    Route::controller(AdminSearchController::class)->prefix('search')->group(function () {
        Route::get('/users', 'searchUsers');
        Route::get('/transactions', 'searchTransactions');
    });

    // 2. مدیریت مالی (Financial)
    Route::controller(AdminFinancialController::class)->prefix('financial')->group(function () {
        Route::get('/company/invoices', 'getCompanyInvoices');
        Route::get('/company/expert-shares', 'getExpertShares');
        Route::get('/supplier/documents', 'getSupplierDocuments');
        Route::get('/expert/documents', 'getExpertDocuments');
    });

    // 3. مدیریت کاربران (Users)
    Route::controller(AdminUserController::class)->prefix('users')->group(function () {
        Route::get('/', 'index');
        Route::get('/{user}', 'show');
        Route::post('/{user}/toggle-status', 'toggleStatus');
        Route::post('/{user}/note', 'addNote');
    });

    // 4. مدیریت سفارشات (Orders)
    Route::controller(AdminOrderController::class)->prefix('orders')->group(function () {
        Route::get('/', 'index');
        Route::get('/{order}', 'show');
        Route::post('/{order}/status', 'changeStatus');
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/checkout', [CheckoutController::class, 'placeOrder']);
    Route::post('/orders/{order}/pay-hold-fee', [OrderController::class, 'payHoldFee']);
    Route::post('/orders/{order}/pay-full', [OrderController::class, 'payFullOrder']);
    Route::get('/orders', [OrderController::class, 'index'])
        ->middleware('track:orders');
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
    Route::get('/orders/{order}/invoice/{type}', [OrderController::class, 'downloadInvoice'])
        ->whereIn('type', ['initial', 'final', 'tax']);
    Route::post('/orders/{order}/prepare', [OrderController::class, 'markAsPrepared']);
    Route::post('/orders/{order}/waybill', [OrderController::class, 'addWaybill']);
    Route::post('/orders/{order}/espadana', [OrderController::class, 'issueEspadanaCommand']);
    Route::post('/orders/{order}/receipt', [OrderController::class, 'attachPaymentReceipt']);

    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::group(['prefix' => 'beneficiaries'], function () {
        Route::post('/start-verification', [\App\Http\Controllers\BeneficiaryController::class, 'startVerification'])->name('startVerification');
        Route::post('/confirm-verification', [\App\Http\Controllers\BeneficiaryController::class, 'confirmVerification'])->name('confirmVerification');
    });

    Route::prefix('stock-subscriptions')->controller(StockSubscriptionController::class)->as('stock.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/toggle', 'toggle')->name('toggle');
    })->middleware(EnsureRoleIsSelected::class);

    Route::post('/orders/{order}/survey', [OrderSurveyController::class, 'store'])->name('orders.survey.store');

    Route::post('/admin/orders/{order}/finalize-invoice', [OrderActionController::class, 'finalizeInvoice'])
        ->name('admin.orders.finalize');

    Route::prefix('wallet')->controller(WalletController::class)->as('wallet.')->group(function () {
        Route::get('/', 'getWallet')->name('show')
            ->middleware('track:wallet');
        Route::get('/transactions', 'getTransactions')->name('transactions');
        Route::post('/deposit', 'requestDeposit')->name('deposit');
        Route::post('/deposit/{transaction}/upload-receipt', 'uploadDepositReceipt')->name('deposit.upload');
        Route::post('/withdraw', 'requestWithdrawal')->name('withdraw');
    });

    Route::prefix('consultants')->controller(ConsultantRelationController::class)->group(function () {

        // --- سمت کارفرما (Employer) ---
        Route::get('/', 'listMyConsultants')->name('list')
            ->middleware('track:relations');
        Route::post('/invite', 'inviteConsultant')->name('invite');
        Route::delete('/{consultant}', 'removeConsultant')->name('remove');

        // --- سمت مشاور فنی (Consultant) ---
        Route::get('/invitations', 'listMyInvitations')->name('invitations');
        Route::post('/invitations/{employer}/accept', 'acceptInvitation')->name('accept');
        Route::post('/invitations/{employer}/reject', 'rejectInvitation')->name('reject');
    });

    Route::apiResource('/bank-accounts', UserBankAccountController::class)
        ->except(['show', 'update']);
})->middleware(EnsureRoleIsSelected::class);

Route::post('/payment/result', action: fn() => response()->json(['message' => 'Callback URL.']));

Route::middleware('auth:sanctum')->group(function () {
    Route::middleware(['role.expert'])->group(function () {

        Route::prefix('education-credentials')->controller(EduixController::class)->as('education.')->group(function () {
            Route::post('/fetch', [EduixController::class, 'fetch']);
            Route::get('/', 'index')->name('list')->middleware('track:education');
            Route::delete('/{record}', 'destroy')->name('destroy');
        });

        Route::apiResource('job-experiences', JobExperienceController::class)
            ->except(['show', 'update'])
            ->middleware('index:track:job');
    });
});
Route::prefix('products')->middleware(['auth:sanctum', 'attach.price.category'])->group(function () {
    Route::get('categories', [ProductCategoryController::class, 'index']);
    Route::get('/', [ProductController::class, 'index']);
    Route::get('/{product:cuid}', [ProductController::class, 'show']);
    Route::get('/{product:cuid}/variants', [ProductController::class, 'variants']);
    Route::get('/{product:cuid}/filters', [ProductController::class, 'filters']);
    Route::post('/variant-price',   [ProductController::class, 'getVariantPrice']);
    Route::post('/calculate-price', [ProductController::class, 'calculateOrderPrice']);
});

Route::prefix('purchase-parameters')->group(function () {
    Route::get('/groups', [PurchaseParameterController::class, 'groups']);
    Route::get('/user', [PurchaseParameterController::class, 'userParameters']);
    Route::post('/user', [PurchaseParameterController::class, 'store']);
    Route::post('/detect-price-filters', [ParameterDetectionController::class, 'detectPriceFilters']);
});

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

Route::prefix('cart')->middleware(['auth:sanctum', 'attach.price.category'])->group(function () {
    Route::get('/',                [CartController::class, 'getCart']);
    Route::post('/items',          [CartController::class, 'addToCart']);
    Route::patch('/items/{item}',  [CartController::class, 'updateCartItem']);
    Route::delete('/items/{item}', [CartController::class, 'removeCartItem']);
    Route::patch('/filters',       [CartController::class, 'updateCartFilters']);
    Route::patch('/customer', [CartController::class, 'updateCustomerType']);
});


Route::group(['prefix' => 'addresses'], function () {
    Route::get('/', [\App\Http\Controllers\AddressController::class, 'index'])
        ->middleware('track:addresses');
    Route::post('/', [\App\Http\Controllers\AddressController::class, 'store']);
    Route::put('/{address}', [\App\Http\Controllers\AddressController::class, 'update']);
    Route::delete('/{address}', [\App\Http\Controllers\AddressController::class, 'destroy']);
});

Route::prefix('wishlists')->middleware('auth:sanctum')->as('wishlists.')->group(function () {
    Route::get('/', [WishlistController::class, 'index'])->name('index')
        ->middleware('track:my_items');
    Route::post('/', [WishlistController::class, 'store'])->name('store');
    Route::get('/{wishlist}', [WishlistController::class, 'show'])->name('show');
    Route::put('/{wishlist}', [WishlistController::class, 'update'])->name('update');
    Route::delete('/{wishlist}', [WishlistController::class, 'destroy'])->name('destroy');

    Route::post('/toggle-variant', [WishlistController::class, 'toggleVariant'])->name('toggleVariant');

    Route::post('/{wishlist}/variants/{variant}', [WishlistController::class, 'addVariant'])->name('variants.add');
    Route::delete('/{wishlist}/variants/{variant}', [WishlistController::class, 'removeVariant'])->name('variants.remove');
});

Route::post('/listophers', [ListopherController::class, 'store']);

Route::get('/search', [SearchController::class, 'search']);
Route::post('/new-question', [QuestionController::class, 'submitQuestion']);

Route::controller(AuthController::class)->group(function () {
    Route::group(['prefix' => 'authenticate'], function () {
        Route::post('/', 'register')->middleware('throttle:sms');
        Route::post('/check-user', 'checkUserAndSendCode');
        Route::post('/verify-code', 'verifyCode');
        Route::post('/password/check', 'checkPassword');
        Route::post('/forget-password', 'forgetPassword')->middleware('throttle:sms');
        Route::post('/change-password', 'changePassword');
        Route::post('/send-code', 'sendVerificationCode')->middleware('throttle:sms');
        Route::middleware('auth:sanctum')->post('/logout', 'logout');
    });
});


Route::middleware('auth:sanctum')->group(function () {


    Route::group(['prefix' => 'province'], function () {
        Route::get('/', action: fn() => success(getProvince()));
        Route::get('/cities/{provinceID?}', fn() => success(getCities()));
    });

    Route::group(['prefix' => 'profile'], function () {

        Route::get('/role-options', [ProfileController::class, 'getRoleOptions']);
        Route::get('/messages', [ProfileController::class, 'getMessages'])
            ->middleware('track:messages');
        Route::get('/messages/read-all', [ProfileController::class, 'updateAllMessageStatus']);
        Route::get('/messages/{userNotification}', [ProfileController::class, 'updateMessageStatus']);
        Route::group(['prefix' => 'change-mobile'], function () {
            Route::get('/', [ProfileController::class, 'updateMobile']);
            Route::post('/request', [ProfileController::class, 'requestChangeMobile']);
            Route::post('/confirm', [ProfileController::class, 'confirmChangeMobile']);
        });
        Route::post('/real', [ProfileController::class, 'updateRealProfile']);
        Route::post('/legal/fetch', [ProfileController::class, 'fetchLegalData']);
        Route::post('/legal', [ProfileController::class, 'updateLegalProfile']);
        Route::get('/', [ProfileController::class, 'getProfile'])->middleware('track:account_info');
        Route::post('/', [ProfileController::class, 'setProfile']);
        Route::put('/', [ProfileController::class, 'updateProfile']);
        Route::post('/verify-email', [ProfileController::class, 'setEmail']);
        Route::post('/password', [ProfileController::class, 'setPassword']);
        Route::get('/change-password/first-attempt', [ProfileController::class, 'changePasswordFirstAttempt']);
        Route::post('/type', [ProfileController::class, 'setUserType'])
            ->withoutMiddleware([EnsureRoleIsSelected::class]);
        Route::group(['prefix' => 'engineer'], function () {
            Route::post('/', [EngineerController::class, 'store']);
        });

        Route::group(['prefix' => 'contractor'], function () {
            Route::post('/', [ContractorController::class, 'store']);
        });

        Route::group(['prefix' => 'supplier'], function () {
            Route::get('/activity-categories', [SupplierController::class, 'getCategories']);
            Route::post('/', [SupplierController::class, 'store']);
            Route::get('/activityCategory-getId', function () {
                return getSupplierCategoryId();
            });
        });

        Route::group(['prefix' => 'employer'], function () {
            Route::post('/', [EmployerController::class, 'store']);
        });
    });

    Route::get('/user-types', [AuthController::class, 'types']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});

Route::post('/check-email-token', [ProfileController::class, 'checkToken']);

Route::post('/check-saze-user', function (Request $request) {
    return [
        'result' => (int)User::query()->where(['mobile' => $request->input('mobile')])->exists()
    ];
});

Route::get('kavir-products', function () {

    $gateway = GatewayFactory::make('tesna');
    $response = $gateway->getRequest('/api/v1/wh/AllStoreCodes');

    return response()->json($response);
});
