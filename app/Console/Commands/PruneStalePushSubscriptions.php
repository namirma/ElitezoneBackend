<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DeviceToken;
use Illuminate\Console\Command;

/**
 * حذفِ اشتراک‌های پوشی که مدت‌هاست دیده نشده‌اند.
 *
 * ⚠️ **چرا حذفِ خودکارِ ۴۰۴/۴۱۰ کافی نبود.** `PushService` اشتراکی را که سرویسِ
 * پوش «منقضی» اعلام کند همان لحظه حذف می‌کند — ولی آن پاسخ فقط وقتی می‌آید که
 * **پیامی فرستاده شود**. کاربری که اپ را پاک کرده بی‌آنکه اجازه را پس بگیرد،
 * یا گوشی‌اش را عوض کرده، تا وقتی اعلانی برایش نرود هیچ بازخوردی نمی‌دهد.
 * ردیفش تا ابد می‌ماند، و وقتی بالاخره پیامی برود، یک درخواستِ محکوم‌به‌شکستِ
 * اضافه در همان flush است. ستونِ `last_seen_at` از روزِ اول برای همین ثبت می‌شد
 * و تا امروز هیچ خواننده‌ای نداشت.
 *
 * **«دیده‌شدن» یعنی چه.** اپ روی هر بوت (`syncExistingSubscription` در فرانت)
 * اشتراکِ موجود را دوباره اعلام می‌کند و `DeviceTokenController::store` زمانش
 * را تازه می‌کند. پس `last_seen_at` قدیمی یعنی این دستگاه مدت‌هاست اپ را با
 * همین حساب باز نکرده.
 *
 * **هزینه‌ی اشتباه، و چرا قابل‌قبول است.** کاربری که اعلان‌ها را می‌گیرد ولی
 * ۹۰ روز اپ را باز نمی‌کند، هرس می‌شود و دیگر پوش نمی‌گیرد — ولی اعلانِ
 * درون‌برنامه‌ای هنوز ثبت می‌شود، و **اولین باری که اپ را باز کند** اشتراک
 * دوباره اعلام و ردیف بازسازی می‌شود. خطا خودش را ترمیم می‌کند؛ ماندنِ ردیف‌های
 * مرده نمی‌کند.
 *
 * ردیفی که `last_seen_at` ندارد (پیش از ثبتِ این ستون، یا ساخته‌شده خارج از
 * کنترلر) با `updated_at` سنجیده می‌شود — نه اینکه همیشه بماند و نه اینکه
 * بی‌دلیل حذف شود.
 *
 * حذف chunk-شده است، مثلِ `searches:prune`، تا یک اجرا روی جدولِ انباشته قفلِ
 * طولانی نسازد.
 */
class PruneStalePushSubscriptions extends Command
{
    protected $signature = 'push:prune-stale {--days= : چند روز دیده‌نشدن (پیش‌فرض از config/services.php)}';

    protected $description = 'حذف اشتراک‌های پوشی که بیش از بازهٔ تعیین‌شده دیده نشده‌اند';

    private const CHUNK = 1000;

    /** گاردِ حلقهٔ بی‌پایان اگر حذف بی‌اثر بماند. */
    private const MAX_ITERATIONS = 10000;

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('services.push.stale_after_days', 90));

        if ($days < 1) {
            $this->error('بازهٔ دیده‌نشدن باید حداقل ۱ روز باشد.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $ids = DeviceToken::query()
                // ⚠️ هر دو شاخه داخلِ یک گروه: یک `orWhere` بدونِ پرانتز، شرطِ
                // اول را از کار می‌انداخت و هر ردیفِ بی‌`last_seen_at`ی را حذف می‌کرد.
                ->where(function ($q) use ($cutoff) {
                    $q->where(DeviceToken::LAST_SEEN_AT, '<', $cutoff)
                        ->orWhere(function ($q) use ($cutoff) {
                            $q->whereNull(DeviceToken::LAST_SEEN_AT)
                                ->where(DeviceToken::UPDATED_AT, '<', $cutoff);
                        });
                })
                ->orderBy(DeviceToken::ID)
                ->limit(self::CHUNK)
                ->pluck(DeviceToken::ID);

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += DeviceToken::whereIn(DeviceToken::ID, $ids)->delete();
        }

        $this->info("حذف {$deleted} اشتراکِ پوش که بیش از {$days} روز دیده نشده بود.");

        return self::SUCCESS;
    }
}
