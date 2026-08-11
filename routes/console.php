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

// لغو سفارشات منقضی‌شده — هر دقیقه
Schedule::command('orders:cancel-expired')
    ->everyMinute()
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

// اسکالیشن خودکار تیکت‌های عبورکرده از SLA (یک‌بار به ازای هر تیکت)
Schedule::job(new EscalateOverdueTicketsJob)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// تطبیقِ دفترِ مالی با موجودی کیف‌ها. برخلافِ صف، یک موجودیِ غلط هیچ‌وقت خودش را
// لو نمی‌دهد — نه خطایی، نه شکایتی — پس تنها راهِ دیدنش سنجیدنِ دوره‌ایِ همین
// تساوی است. ۰۲:۴۵ انتخاب شده تا بعد از پشتیبان‌گیری اجرا شود: اگر مغایرتی هست،
// دامپِ همان شب حالتِ قبل از کشف را نگه داشته.
Schedule::command('financial:doctor')
    ->dailyAt('02:45')
    ->withoutOverlapping()
    ->onOneServer();
