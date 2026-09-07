<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\StockSubscription;
use App\Models\UserNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * اعلانِ «دوباره موجود شد» به کاربرانی که اشتراکِ اطلاع‌رسانی ثبت کرده‌اند.
 *
 * ⚠️ **چرا این وجود نداشت و چرا لازم است.** `stock_subscriptions` از ابتدا
 * ساخته شده بود، اندپوینتِ `POST /profile/stock-subscriptions/toggle` ثبتِ
 * اشتراک را قبول می‌کرد، ستونِ `notified_at` هم در مهاجرت بود — ولی **هیچ
 * کدی در کلِ پروژه آن ستون را نمی‌نوشت و هیچ اعلانی نمی‌فرستاد**. نه جاب، نه
 * لیسنر، نه observer. یعنی کاربر روی «خبرم کن موجود شد» می‌زد، ردیف ذخیره
 * می‌شد، و تا ابد هیچ اتفاقی نمی‌افتاد. دقیقاً همان «قابلیتی که وجود دارد ولی
 * کار نمی‌کند» که قاعدهٔ صداقتِ این پروژه ممنوع می‌کند.
 *
 * ## چرا کامندِ زمان‌بندی‌شده و نه observer
 *
 * موجودی از چند مسیر بالا می‌رود و **همه‌شان رویدادِ مدل نمی‌زنند**:
 * `FactoryStock::syncVariantRollup()` و بخشی از `InventoryService` با
 * query builder می‌نویسند (`->update()` / `->increment()` روی Builder)، که
 * `updated` را اصلاً fire نمی‌کند. پس یک observer بی‌صدا نیمی از حالت‌ها را از
 * دست می‌داد — همان نوع خرابیِ خاموشی که این کامند قرار است تمامش کند.
 *
 * یک کوئریِ دوره‌ای، منبعِ افزایشِ موجودی برایش بی‌اهمیت است. ضمناً اعلان را از
 * داخلِ تراکنش‌های مالی (باسکول/لغو سفارش) بیرون نگه می‌دارد.
 *
 * ## ایدمپوتنسی
 *
 * `notified_at` هم فیلترِ ورودی است هم مهرِ خروجی، و درج و مهر در یک تراکنش
 * انجام می‌شوند: اجرای دوباره ردیف‌های مهرخورده را نمی‌بیند، و شکستِ میانه‌راه
 * هر دو را برمی‌گرداند تا اجرای بعدی دوباره تلاش کند. `chunkById` (نه `chunk`)
 * چون همین کوئری ردیف‌ها را از فیلترِ خودش خارج می‌کند و صفحه‌بندیِ offsetی در
 * آن حالت ردیف رد می‌کند.
 */
class NotifyStockSubscribers extends Command
{
    protected $signature = 'stock:notify-subscribers';

    protected $description = 'ارسال اعلان «موجود شد» به مشترکانِ اطلاع‌رسانیِ موجودی';

    /** `user_notifications.subject` واقعاً `varchar(64)` است. */
    private const SUBJECT_MAX = 64;

    /** `user_notifications.body` واقعاً `varchar(255)` است. */
    private const BODY_MAX = 255;

    public function handle(): int
    {
        $sent = 0;

        StockSubscription::query()
            ->whereNull(StockSubscription::NOTIFIED_AT)
            ->whereHas('productVariant', fn ($q) => $q->where('stock_quantity', '>', 0))
            ->with('productVariant.product')
            ->chunkById(200, function ($subscriptions) use (&$sent) {
                $now = now();
                $rows = [];
                $ids = [];

                foreach ($subscriptions as $subscription) {
                    $product = $subscription->productVariant?->product;

                    // ردیفِ یتیم (واریانت یا محصولش حذف شده) نه اعلان می‌گیرد نه
                    // مهر می‌خورد — وگرنه «فرستاده شد» ثبت می‌شد بدون اینکه
                    // چیزی فرستاده شده باشد.
                    if ($product === null) {
                        continue;
                    }

                    $rows[] = [
                        UserNotification::USER_ID => (int) $subscription->{StockSubscription::USER_ID},
                        UserNotification::SENDER_ID => null,
                        UserNotification::BATCH_ID => null,
                        UserNotification::SUBJECT => mb_substr(__('stock.back_in_stock_subject'), 0, self::SUBJECT_MAX),
                        UserNotification::BODY => mb_substr(
                            __('stock.back_in_stock_body', ['product' => (string) $product->name]),
                            0,
                            self::BODY_MAX
                        ),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $ids[] = (int) $subscription->{StockSubscription::ID};
                }

                if ($rows === []) {
                    return;
                }

                DB::transaction(function () use ($rows, $ids, $now) {
                    UserNotification::insert($rows);

                    StockSubscription::whereIn(StockSubscription::ID, $ids)->update([
                        StockSubscription::NOTIFIED_AT => $now,
                        StockSubscription::UPDATED_AT => $now,
                    ]);
                });

                $sent += count($rows);
            });

        $this->info(__('stock.notify_done', ['count' => $sent]));

        return self::SUCCESS;
    }
}
