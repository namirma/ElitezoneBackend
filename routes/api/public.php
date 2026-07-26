<?php

use App\Http\Controllers\AgreementController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\QuestionController;
use App\Http\Controllers\Api\User\ProductReviewController;
use App\Http\Controllers\CustomerTypeController;
use App\Http\Controllers\FactoryController;
use App\Http\Controllers\ParameterDetectionController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PriceFilterController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseParameterController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ShippingMethodController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
| روت‌های بدون نیاز به احراز هویت + endpointهای authenticate.
| logout که نیاز به auth:sanctum دارد در همان گروه تعریف شده.
*/

// ── Payment gateway callbacks (public) ────────────────────────────────────
// throttle: این endpointهای عمومی‌اند و بدون سقف قابل flood/forge بودند؛ verbها هم
// به POST/GET محدود شد (Route::any قبلاً PUT/PATCH/DELETE را هم می‌پذیرفت). امنیت
// نهایی همچنان به راستی‌آزماییِ امضای درگاه در PaymentController وابسته است.
Route::post('/payment/result', [PaymentController::class, 'result'])->middleware('throttle:30,1');
Route::match(['get', 'post'], '/payment/callback', [PaymentController::class, 'callback'])
    ->middleware('throttle:30,1')
    ->name('payment.callback');

// ── Search / Question (public) ────────────────────────────────────────────
// throttle: هر درخواست یک ردیف در جدول searches می‌نویسد — بدون سقف، endpoint
// عمومی قابل flood بود و «جستجوهای محبوب» دستکاری می‌شد.
Route::get('/search', [SearchController::class, 'search'])->middleware('throttle:30,1');
// throttle: endpoint عمومیِ ثبتِ سوال بدونِ سقف قابلِ اسپم/flood بود (هر درخواست یک
// رکورد می‌سازد). مثلِ بقیهٔ endpointهای عمومی محدود شد.
Route::post('/new-question', [QuestionController::class, 'submitQuestion'])->middleware('throttle:10,1');

// ── Authentication ────────────────────────────────────────────────────────
Route::controller(AuthController::class)->prefix('authenticate')->group(function () {
    Route::post('/', 'register')->middleware('throttle:sms');
    Route::post('/check-user', 'checkUserAndSendCode')->middleware('throttle:sms');
    Route::post('/verify-code', 'verifyCode')->middleware('throttle:otp-verify');
    Route::post('/password/check', 'checkPassword')->middleware('throttle:otp-verify');
    Route::post('/forget-password', 'forgetPassword')->middleware('throttle:sms');
    Route::post('/change-password', 'changePassword')->middleware('throttle:otp-verify');
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
    // نظرات تأییدشده محصول (عمومی) + آمار امتیاز — پشت فلگ features.product_reviews
    // (پیش‌فرض خاموش: این قابلیت در محصولِ فعلی وجود ندارد؛ config/features.php)
    if (config('features.product_reviews')) {
        Route::get('/{product:cuid}/reviews', [ProductReviewController::class, 'productReviews']);
    }
    Route::post('/variant-price', [ProductController::class, 'getVariantPrice']);
});

// ── Lookup tables (public + cached) ───────────────────────────────────────
Route::prefix('factories')->group(function () {
    Route::get('/', [FactoryController::class, 'listFactories']);
    Route::get('/{id}/locations', [FactoryController::class, 'listLocations']);
});

// روش‌های ارسال (lookup عمومی)
Route::get('/shipping-methods', [ShippingMethodController::class, 'index']);

// ── محتوای عمومی سایت (مدیریت‌شده در پنل ادمین، فقط رکوردهای فعال، کش‌شده) ──
Route::controller(\App\Http\Controllers\ContentController::class)->group(function () {
    Route::get('/banners', 'banners');
    Route::get('/articles', 'articles');
    Route::get('/articles/{slug}', 'article');
    Route::get('/faq', 'faq');
    // دسته‌ها + سوالاتشان (درختی) — کشوی پشتیبانیِ سایت اصلی از این می‌خواند
    Route::get('/faq-categories', 'faqCategories');
    // مگامنوی نوار بالای سایت
    Route::get('/menu', 'menu');
    Route::get('/pages/{slug}', 'page');
    Route::get('/settings/public', 'publicSettings');
});

Route::prefix('customer-types')->group(function () {
    Route::get('/', [CustomerTypeController::class, 'index']);
    Route::get('/badges', [CustomerTypeController::class, 'getBadges']);
});

Route::prefix('price-filters')->group(function () {
    Route::get('/categories', [PriceFilterController::class, 'getCategories']);
    Route::get('/types', [PriceFilterController::class, 'getFilterTypes']);
});

// توافق‌نامه‌های آنلاین — آخرین نسخه فعال برای صفحه پذیرش (متن + چک‌باکس‌ها + PDF)
Route::get('/agreements/{slug}', [AgreementController::class, 'show'])
    ->where('slug', '[a-z_]+');
Route::get('/agreements/{slug}/pdf', [AgreementController::class, 'publicPdf'])
    ->where('slug', '[a-z_]+')
    ->middleware('throttle:10,1');

Route::prefix('purchase-parameters')->group(function () {
    Route::get('/groups', [PurchaseParameterController::class, 'groups']);
    Route::get('/user', [PurchaseParameterController::class, 'userParameters']);
    Route::post('/user', [PurchaseParameterController::class, 'store']);
    Route::post('/detect-price-filters', [ParameterDetectionController::class, 'detectPriceFilters']);
});
