<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\QuestionController;
use App\Http\Controllers\CustomerTypeController;
use App\Http\Controllers\FactoryController;
use App\Http\Controllers\ParameterDetectionController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PriceFilterController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseParameterController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
| روت‌های بدون نیاز به احراز هویت + endpointهای authenticate.
| logout که نیاز به auth:sanctum دارد در همان گروه تعریف شده.
*/

// ── Payment gateway callbacks (public) ────────────────────────────────────
Route::post('/payment/result', [PaymentController::class, 'result']);
Route::any('/payment/callback', [PaymentController::class, 'callback'])->name('payment.callback');

// ── Search / Question (public) ────────────────────────────────────────────
Route::get('/search', [SearchController::class, 'search']);
Route::post('/new-question', [QuestionController::class, 'submitQuestion']);

// ── Authentication ────────────────────────────────────────────────────────
Route::controller(AuthController::class)->prefix('authenticate')->group(function () {
    Route::post('/', 'register')->middleware('throttle:sms');
    Route::post('/check-user', 'checkUserAndSendCode');
    Route::post('/verify-code', 'verifyCode')->middleware('throttle:otp-verify');
    Route::post('/password/check', 'checkPassword');
    Route::post('/forget-password', 'forgetPassword')->middleware('throttle:sms');
    Route::post('/change-password', 'changePassword');
    Route::post('/send-code', 'sendVerificationCode')->middleware('throttle:sms');
    Route::middleware('auth:sanctum')->post('/logout', 'logout');
});

// ── Products & categories (public listing) ────────────────────────────────
Route::prefix('products')->middleware(['attach.price.category'])->group(function () {
    Route::get('categories', [ProductCategoryController::class, 'index']);
    Route::get('/', [ProductController::class, 'index']);
    Route::get('/{product:cuid}', [ProductController::class, 'show']);
    Route::get('/{product:cuid}/variants', [ProductController::class, 'variants']);
    Route::get('/{product:cuid}/filters', [ProductController::class, 'filters']);
    Route::post('/variant-price', [ProductController::class, 'getVariantPrice']);
});

// ── Lookup tables (public + cached) ───────────────────────────────────────
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
