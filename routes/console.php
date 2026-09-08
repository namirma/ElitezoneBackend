<?php

use App\Jobs\Analytics\CalculateCustomerRfm;
use App\Jobs\Analytics\PredictInventoryNeeds;
use App\Jobs\Analytics\RollupProductViewsJob;
use App\Jobs\Financial\CheckSupplierBalancesJob;
use App\Jobs\Financial\PollPodiumDepositsJob;
use App\Jobs\Ticketing\EscalateOverdueTicketsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| فرمان‌های Closure-based و زمان‌بندی‌های Schedule اینجا تعریف می‌شوند.
| در لاراول ۱۳، Schedule از routes/console.php بارگذاری می‌شود.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Task Scheduling (سابقاً در Console/Kernel.php)
|--------------------------------------------------------------------------
*/

// محموله‌های تأییدنشده پس از مهلت تحویل ⇒ عودت به مبدأ (بند ۳.۱۱).
// هر دقیقه، چون مهلت روی هر محموله جداگانه است و تأخیر یعنی بارِ عودت‌شده
// مدتی «هنوز در انتظار تأیید» به نظر برسد.
Schedule::command('shipments:expire-delivery')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->onOneServer();

// پایشِ سلامتِ صف — هر ۱۵ دقیقه. همهٔ listenerها ShouldQueue هستند، پس
// خوابیدنِ worker هیچ خطایی تولید نمی‌کند و فقط پیامکِ OTP کاربر نمی‌رسد.
// این دستور آن خرابیِ خاموش را به یک لاگِ error (و در نتیجه یک رویداد
// Sentry، اگر DSN ست باشد) تبدیل می‌کند.
Schedule::command('queue:health')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// درخواست‌های اضافه‌بارِ بی‌پاسخ (بند ۳.۷). مهلتش ساعتی است نه دقیقه‌ای،
// پس ساعتی کافی است — و بارِ کوئری را روی جدول سفارش‌ها کم می‌کند.
Schedule::command('orders:expire-overweight')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->onOneServer();

// سینک محصولات کویر — هر ساعت
Schedule::command('products:sync-kavir')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->onOneServer();

// محاسبه RFM مشتری — روزانه ۰۳:۰۰
Schedule::job(new CalculateCustomerRfm)
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();

// پیش‌بینی نیاز موجودی — روزانه ۰۴:۰۰
Schedule::job(new PredictInventoryNeeds)
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->onOneServer();

// تجمیع روزانه بازدید محصول + حذف داده‌های خام منقضی — ۰۲:۳۰
// آنالیتیکس از جدول تجمیعی می‌خواند نه raw
Schedule::job(new RollupProductViewsJob)
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer();

// بررسی موجودی تأمین‌کننده و آزادسازی هولدهای باز — هر دقیقه
// withoutOverlapping حیاتی است: اجرای همزمان می‌تواند هولد را دو بار آزاد کند
Schedule::job(new CheckSupplierBalancesJob)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Polling صورت‌حساب سپرده پادیوم — شارژ خودکار کیف‌پول با شناسه واریز
Schedule::job(new PollPodiumDepositsJob)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// حذف توکن‌های Sanctum منقضی‌شده — روزانه (۱۶۸ ساعت = ۷ روز)
Schedule::command('sanctum:prune-expired --hours=168')
    ->daily()
    ->onOneServer();

// حذف سبدهای مهمانِ رهاشده — /api/cart عمومی است و بازدیدِ بدونِ کوکی
// (خزنده/ربات) هر بار یک ردیف carts می‌سازد که هیچ سیاست نگهداشتی نداشت.
Schedule::command('carts:prune-guests')
    ->dailyAt('01:30')
    ->withoutOverlapping()
    ->onOneServer();

// رزروهایی که مهلت **و** بازه‌ی ارفاقشان تمام شده (بند ۳.۴ / ۱۰.۳).
Schedule::command('orders:expire-reserves')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// ── سبدِ مهلت‌دار (Park) — بند ۳.۱ و ۱۰.۳ سند بازطراحی ───────────────────
//
// انقضا هر دقیقه اجرا می‌شود چون مهلتِ سبد دقیق است و کاربر شمارشِ معکوس را
// روی صفحه می‌بیند؛ با فاصله‌ی بیشتر، سبدی که فرانت «منقضی» نشان می‌دهد هنوز
// چند دقیقه در دیتابیس زنده می‌ماند.
Schedule::command('carts:expire-parked')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// هشدار هر ۵ دقیقه کافی است — پنجره‌اش ۳۰ دقیقه است و `park_warned_at` جلوی
// ارسالِ دوباره را می‌گیرد.
Schedule::command('carts:warn-expiring')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// اعلانِ «دوباره موجود شد» به مشترکانِ اطلاع‌رسانیِ موجودی.
//
// ⚠️ تا پیش از این **هیچ‌چیز** این اعلان را نمی‌فرستاد: جدول و اندپوینتِ ثبتِ
// اشتراک وجود داشت ولی هیچ کدی `notified_at` را نمی‌نوشت، پس قابلیت از دید
// کاربر کار می‌کرد و در عمل هرگز چیزی نمی‌فرستاد.
//
// هر ۱۰ دقیقه: «موجود شد» مهلتِ دقیقه‌ای ندارد و کوئری‌اش روی کلِ مشترکانِ
// منتظر اجرا می‌شود، پس اجرای هر دقیقه فقط بار اضافه است.
Schedule::command('stock:notify-subscribers')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// نگهداشتِ لاگِ جستجو — `/api/search` عمومی است و به‌ازای هر درخواست یک ردیف
// می‌نویسد (عبارت + user_id + IP + User-Agent). `product_views` و سبدِ مهمان
// سیاستِ نگهداشت داشتند و این یکی جا مانده بود: هم رشدِ نامحدود، هم تاریخچه‌ی
// جستجوی افراد که برای همیشه می‌ماند.
Schedule::command('searches:prune')
    ->dailyAt('01:40')
    ->withoutOverlapping()
    ->onOneServer();

// اسکالیشن خودکار تیکت‌های عبورکرده از SLA (یک‌بار به ازای هر تیکت)
Schedule::job(new EscalateOverdueTicketsJob)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// ── پشتیبان‌گیری دیتابیس ────────────────────────────────────────────────────
// spatie/laravel-backup روی این شاخه اصلاً نصب نبود: `config/backup.php` پورت شده
// بود ولی نه پکیج و نه زمان‌بندی — یعنی شاخه‌ای که قرار است مستقر شود، سیستمی با
// کیف پول و لجر مالی را **بدون هیچ نسخهٔ پشتیبانی** بالا می‌آورد. دامپ روی دیسکِ
// خصوصی `financial` نوشته می‌شود (config/backup.php) چون شامل کدملی/شبا/شمارهٔ کارت است.
Schedule::command('backup:run --only-db')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->onOneServer();

// اعمال سیاست نگهداشت — بعد از دامپ اجرا می‌شود تا نسخهٔ تازه لحاظ شود.
Schedule::command('backup:clean')
    ->dailyAt('01:45')
    ->withoutOverlapping()
    ->onOneServer();

// پایشِ سلامت نسخه‌های پشتیبان — اگر آخرین نسخه کهنه یا حجم از سقف رد شده باشد
// اعلان می‌دهد. بدون این، خرابیِ بی‌صدای دامپ تا روزِ بازگردانی معلوم نمی‌شد.
Schedule::command('backup:monitor')
    ->dailyAt('02:00')
    ->onOneServer();

// تطبیقِ دفترِ مالی با موجودی کیف‌ها. برخلافِ صف، یک موجودیِ غلط هیچ‌وقت خودش را
// لو نمی‌دهد — نه خطایی، نه شکایتی — پس تنها راهِ دیدنش سنجیدنِ دوره‌ایِ همین
// تساوی است. ۰۲:۴۵ انتخاب شده تا بعد از پشتیبان‌گیری اجرا شود: اگر مغایرتی هست،
// دامپِ همان شب حالتِ قبل از کشف را نگه داشته.
Schedule::command('financial:doctor')
    ->dailyAt('02:45')
    ->withoutOverlapping()
    ->onOneServer();
