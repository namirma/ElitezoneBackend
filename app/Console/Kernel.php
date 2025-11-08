<?php

namespace App\Console;

use App\Console\Commands\CancelExpiredOrders;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        CancelExpiredOrders::class,
    ];
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
{
        // $schedule->command('inspire')->hourly();

        // 🚀 3. افزودن تسک زمان‌بندی شده برای لغو سفارش‌ها
        $schedule->command('orders:cancel-expired')
                 ->everyMinute() // این دستور هر دقیقه اجرا می‌شود
                 ->withoutOverlapping(); // جلوگیری از اجرای همزمان در صورت طول کشیدن
    }
    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
