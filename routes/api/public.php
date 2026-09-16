<?php

use App\Http\Controllers\AgreementController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PromoOverlayController;
use App\Http\Controllers\Api\QuestionController;
use App\Http\Controllers\Api\Shipping\ShipmentDeliveryController;
use App\Http\Controllers\Api\User\ProductReviewController;
use App\Http\Controllers\ContentController;
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

// ── ورودِ دومرحله‌ای (TOTP) — مرحله‌ی دوم ────────────────────────────────────
// `{challenge, code | recovery_code}` → توکن. چالش را verify-code / password/check /
// change-password برای کاربرِ دارای دومرحله‌ای به‌جای توکن برمی‌گردانند.
// throttle:otp-verify — کدِ ۶ رقمی brute-force پذیر است؛ کلیدِ محدودکننده روی
// این مسیر `challenge` است (AppServiceProvider)، چون `username`ای در بدنه نیست.
Route::post('/login/2fa', [AuthController::class, 'verifyTwoFactor'])->middleware('throttle:otp-verify');

// ── Products & categories (public listing) ────────────────────────────────
Route::prefix('products')->middleware(['attach.price.category'])->group(function () {
    Route::get('categories', [ProductCategoryController::class, 'index']);
    Route::get('/', [ProductController::class, 'index']);
    // قبل از {product:cuid} — وگرنه «variants» به‌عنوان مقدار cuid تفسیر و ۴۰۴ می‌گیرد
    Route::get('/variants/{variant}', [ProductController::class, 'showVariant']);
    Route::post('/variants/{variant}/view', [ProductController::class, 'recordVariantView'])->middleware('throttle:60,1');
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
Route::controller(ContentController::class)->group(function () {
    Route::get('/banners', 'banners');
    Route::get('/articles', 'articles');
    Route::get('/articles/{slug}', 'article');
    Route::get('/faq', 'faq');
    // دسته‌ها + سوالاتشان (درختی) — کشوی پشتیبانیِ سایت اصلی از این می‌خواند
    Route::get('/faq-categories', 'faqCategories');
    // مگامنوی نوار بالای سایت
    Route::get('/menu', 'menu');
    // بلوک‌های صفحاتِ محتواییِ سه‌گانه — قبل از pages/{slug} تا ترتیب موضوعِ بحث نشود
    // (سه سگمنت در برابر دو تا، تداخلی هم نیست)
    Route::get('/pages/{page}/blocks', 'pageBlocks');
    Route::get('/pages/{slug}', 'page');
    Route::get('/settings/public', 'publicSettings');
});

// اورلی‌های ترغیب که برای کاربرِ فعلی واجد شرایط‌اند.
// عمومی است (مهمان هم اورلیِ خوش‌آمد را می‌گیرد)، ولی `auth:sanctum` به‌صورت
// اختیاری اعمال می‌شود تا اگر توکن آمد، شرط‌های وابسته به کاربر هم ارزیابی شوند.
Route::get('/promo-overlays', [PromoOverlayController::class, 'index'])
    ->middleware('optional.sanctum');

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

// ── چرخه‌ی تحویل: مسیرهای امضاشده (فاز ۱۰، بند ۷.۵) ─────────────────────────
//
// عمداً **بدون `auth:sanctum`**. راننده طبق بند ۳.۹ حساب کاربری ندارد و ارتباط
// فقط از راه پیامک و لینک امضاشده است؛ خریدار هم ممکن است اپ را باز نکند و
// بند ۳.۱۰ صریحاً «پنل خود یا لینک پیامکی» می‌گوید.
//
// امضا تنها گاردِ این دو مسیر است، پس هیچ‌کدام پارامتری از بدنه نمی‌خوانند و
// انقضای لینک همان `delivery_deadline` است — نه بیشتر، وگرنه پس از عودت هم
// قابل استفاده می‌ماند. `throttle` چون یک لینکِ لو رفته نباید قابل کوبیدن باشد.
//
// ⚠️ **GET فقط صفحه‌ی تأیید است؛ تغییرِ وضعیت فقط با POST.** این لینک با پیامک
// می‌رود، و پیش‌نمایشِ لینک در پیام‌رسان، اسکنرِ امنیتی و click-trackingِ درگاهِ
// پیامک همان URLِ امضاشده را GET/HEAD می‌کنند — امضا در برابرشان محافظتی ندارد.
// وقتی GET خودش تحویل را ثبت می‌کرد، یک پیش‌واکشی سفارش را «تحویل‌شده» می‌کرد و
// پنجره‌ی اصلاحِ وزنِ باسکول (`ProcessWeighbridgeResultAction`) بسته می‌شد — یعنی
// حقِ اصلاحِ یک عددِ مالی از تأمین‌کننده گرفته می‌شد. پیش‌واکشی‌کننده فرم ارسال
// نمی‌کند. امضا روی مسیر + query است، پس POST به همان URL هم معتبر است.
Route::middleware(['signed', 'throttle:30,1'])->group(function () {
    Route::get('/shipments/{shipment}/arrived', [ShipmentDeliveryController::class, 'arrivedPage'])
        ->whereNumber('shipment')
        ->name('shipments.arrived');
    Route::post('/shipments/{shipment}/arrived', [ShipmentDeliveryController::class, 'arrived'])
        ->whereNumber('shipment')
        ->name('shipments.arrived.submit');

    Route::get('/delivery/{shipment}/confirm', [ShipmentDeliveryController::class, 'confirmPage'])
        ->whereNumber('shipment')
        ->name('delivery.confirm');
    Route::post('/delivery/{shipment}/confirm', [ShipmentDeliveryController::class, 'confirmSigned'])
        ->whereNumber('shipment')
        ->name('delivery.confirm.submit');
});
