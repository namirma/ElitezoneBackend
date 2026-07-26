<?php

namespace App\Console;

use App\Console\Commands\CancelExpiredOrders;
use App\Console\Commands\PruneGuestCarts;
use App\Jobs\Analytics\CalculateCustomerRfm;
use App\Jobs\Analytics\PredictInventoryNeeds;
use App\Jobs\Analytics\RollupProductViewsJob;
use App\Jobs\Financial\CheckSupplierBalancesJob;
use App\Jobs\Financial\PollPodiumDepositsJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        CancelExpiredOrders::class,
        PruneGuestCarts::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();

        // 3. افزودن تسک زمان‌بندی شده برای لغو سفارش‌ها
        $schedule->command('orders:cancel-expired')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground()
            ->onOneServer();

        // سینک محصولات کویر (سنگین)
        $schedule->command('products:sync-kavir')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground()
            ->onOneServer();
        // ->emailOutputOnFailure('admin@elitezone.me')
        $schedule->job(new CalculateCustomerRfm)
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->job(new PredictInventoryNeeds)
            ->dailyAt('04:00')
            ->withoutOverlapping()
            ->onOneServer();

        // اسکالیشن خودکار تیکت‌های عبورکرده از SLA (یک‌بار به ازای هر تیکت)
        $schedule->job(new \App\Jobs\Ticketing\EscalateOverdueTicketsJob)
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer();

        // تجمیع روزانه‌ی بازدید محصول در product_view_daily + حذف داده‌ی خامِ منقضی
        // (retention/حریم خصوصی). آنالیتیکس از جدول تجمیعی می‌خواند نه raw.
        $schedule->job(new RollupProductViewsJob)
            ->dailyAt('02:30')
            ->withoutOverlapping()
            ->onOneServer();

        // withoutOverlapping حیاتی است: اجرای همزمان دو نمونه می‌تواند
        // هولد بازگشت وجه خریدار را دو بار آزاد کند
        $schedule->job(new CheckSupplierBalancesJob)
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer();

        // polling صورت‌حساب سپرده پادیوم — شارژ خودکار کیف‌پول با شناسه واریز
        // (runInBackground حذف شد — job خودش ShouldQueue دارد و async اجرا می‌شود)
        $schedule->job(new PollPodiumDepositsJob)
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        // حذف توکن‌های منقضی‌شده — با 168 ساعت تطابق با SANCTUM_TOKEN_EXPIRY_MINUTES=10080
        $schedule->command('sanctum:prune-expired --hours=168')
            ->daily()
            ->onOneServer();

        // حذف سبدهای مهمانِ رهاشده — /api/cart عمومی است و بازدیدِ بدونِ کوکی
        // (خزنده/ربات) هر بار یک ردیف carts می‌سازد که هیچ سیاست نگهداشتی نداشت.
        $schedule->command('carts:prune-guests')
            ->dailyAt('01:30')
            ->withoutOverlapping()
            ->onOneServer();

        // ── پشتیبان‌گیری دیتابیس ────────────────────────────────────────────
        // spatie/laravel-backup نصب بود ولی نه کانفیگ داشت نه زمان‌بندی: یعنی سیستمی
        // با کیف پول و لجر مالی، هیچ نسخهٔ پشتیبانی نداشت. دامپ روی دیسک خصوصی
        // `financial` نوشته می‌شود (config/backup.php) چون شامل کدملی/شبا/شمارهٔ کارت است.
        $schedule->command('backup:run --only-db')
            ->dailyAt('01:00')
            ->withoutOverlapping()
            ->onOneServer();

        // اعمال سیاست نگهداشت — بعد از دامپ اجرا می‌شود تا نسخهٔ تازه لحاظ شود.
        $schedule->command('backup:clean')
            ->dailyAt('01:45')
            ->withoutOverlapping()
            ->onOneServer();

        // پایشِ سلامت نسخه‌های پشتیبان — اگر آخرین نسخه کهنه یا حجم از سقف رد شده
        // باشد اعلان می‌دهد. بدون این، خرابیِ بی‌صدای دامپ تا روزِ بازگردانی معلوم نمی‌شد.
        $schedule->command('backup:monitor')
            ->dailyAt('02:00')
            ->onOneServer();

        // پایشِ سلامتِ صف — هر ۱۵ دقیقه. همهٔ listenerها ShouldQueue هستند، پس
        // خوابیدنِ worker هیچ خطایی تولید نمی‌کند و فقط پیامکِ OTP کاربر نمی‌رسد.
        // این دستور آن خرابیِ خاموش را به یک لاگِ error (و در نتیجه یک رویداد
        // Sentry، اگر DSN ست باشد) تبدیل می‌کند.
        $schedule->command('queue:health')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->onOneServer();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
