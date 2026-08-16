<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Exceptions\BusinessException;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * تمدیدِ مهلتِ یک رزرو — بند ۳.۴.
 *
 * **فقط تأمین‌کننده** و **فقط افزایشی**. دو قاعده که هر دو دلیلِ مشخص دارند:
 *
 * ۱. تمدید حقِ خریدار نیست: کالا برای او کنار گذاشته شده و هزینه‌اش را
 *    تأمین‌کننده می‌دهد (موجودیِ قفل‌شده). پس تصمیمش هم با اوست — و در مدلِ
 *    جدید رایگان است، برخلافِ `PayHoldFeeAction` که حذف شد.
 *
 * ۲. **کوتاه‌کردن ممنوع.** خریدار روی مهلتی که به او اعلام شده برنامه‌ریزی
 *    کرده؛ جلو کشیدنش یعنی رزروش زودتر از موعدِ اعلام‌شده منقضی شود. همان
 *    قاعده‌ای که روی `supplier_profiles.reserve_time` هم هست.
 *
 * `reserve_deadline_original` **دست نمی‌خورد** — پنجره‌ی لغوِ خریدار دقیقاً از
 * روی فاصله‌ی همین دو ستون ساخته می‌شود.
 *
 * تمدید در بازه‌ی ارفاق هم مجاز است (بند ۳.۴: «ارفاق پیش از لغو»): تا وقتی
 * سفارش هنوز `reserved` است و لغو نشده، تأمین‌کننده می‌تواند نجاتش دهد.
 *
 * پوششِ تست: tests/Feature/Order/ReserveDeadlineTest.php
 */
final class ExtendReserveDeadlineAction
{
    public function execute(Order $order, CarbonInterface $newDeadline): Order
    {
        return DB::transaction(function () use ($order, $newDeadline) {
            /** @var Order|null $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            if (! $locked || $locked->{Order::STATUS} !== Order::STATUS_RESERVED) {
                throw new BusinessException(__('orders.reserve_only_on_reserved_orders'), 422);
            }

            $current = $locked->{Order::RESERVE_DEADLINE};

            if ($current !== null && ! $newDeadline->greaterThan($current)) {
                throw new BusinessException(__('orders.reserve_deadline.cannot_shorten'), 422);
            }

            $locked->forceFill([
                Order::RESERVE_DEADLINE => $newDeadline,
                // ستون بازنشسته تا فاز ۱۴ هم‌گام نگه داشته می‌شود.
                Order::RESERVATION_EXPIRES_AT => $newDeadline,
            ])->save();

            return $locked;
        });
    }
}
