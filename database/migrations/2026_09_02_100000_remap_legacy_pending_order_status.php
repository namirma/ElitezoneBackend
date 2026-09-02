<?php

use App\Enums\Order\OrderStatus;
use App\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * وضعیتِ هشتمِ حذف‌شده: `pending`.
 *
 * ‏`2026_08_11_110000_remap_deleted_order_statuses` هفت وضعیت را نگاشت ولی
 * `pending` را جا انداخت — و چون `orders.status` یک `varchar` است، هیچ قیدی
 * جلوی ماندنش را نگرفت و هیچ تستی هم قرمز نشد (سوئیت روی دیتابیسِ تازه با
 * `DatabaseTransactions` اجرا می‌شود و هرگز ردیفِ قدیمی نمی‌بیند).
 *
 * اثرش دقیقاً همان چیزی است که آن مهاجرت برای بقیه جلویش را گرفت: هر سه
 * پارتیشنِ `OrderBuilder::current/delivered/cancelled` ردش می‌کنند، پس سفارش
 * از **هر سه تبِ** «سفارش‌های من» ناپدید می‌شود، و در بک‌آفیس با برچسبِ
 * «نامشخص» رندر می‌شود که فیلترِ وضعیت هم شاملش نمی‌شود.
 *
 * چرا مهاجرتِ تازه و نه یک کلید در همان `MAP`: آن مهاجرت روی هر محیطی که
 * مایگریت کرده دیگر اجرا نمی‌شود.
 *
 * ## دو مقصد، نه یکی
 *
 * در مدل جدید «سفارشِ ساخته‌شده ولی پرداخت‌نشده» اصلاً وجود ندارد — رکورد فقط
 * داخلِ `pay()`/`reserve()`/`payForBeneficiary()` و همیشه با وضعیتِ
 * `processing` یا `reserved` ساخته می‌شود. پس یک `pending` قدیمی هرگز قابل
 * پرداخت نیست و همان جایی می‌رود که `failed` رفت: `cancelled`.
 *
 * **مگر آنکه پرداخت شده باشد.** در مدلِ قدیم سفارش `pending` + `payment_status
 * = pending` ساخته می‌شد و با پرداخت به `processing` + `paid` می‌رفت؛ ردیفی که
 * `paid` است ولی هنوز `pending` مانده یعنی نوشتنِ وضعیت نیمه‌کاره مانده. لغوِ
 * چنین ردیفی پولِ گرفته‌شده را پشتِ برچسبِ «لغو شده» پنهان می‌کند، بی‌آنکه
 * هیچ لِگِ بازگشتی اجرا شود — یعنی همان کلاس باگی که این نگاشت برای بستنش
 * آمده. آن‌ها به `processing` می‌روند تا در پنل دیده و پیگیری شوند.
 */
return new class extends Migration
{
    public function up(): void
    {
        $paid = DB::table('orders')
            ->where('status', 'pending')
            ->where(Order::PAYMENT_STATUS, Order::PAYMENT_PAID)
            ->update(['status' => Order::STATUS_PROCESSING]);

        $unpaid = DB::table('orders')
            ->where('status', 'pending')
            ->update(['status' => Order::STATUS_CANCELLED]);

        if ($paid > 0) {
            Log::channel('financial')->warning(
                "remap_legacy_pending_order_status: {$paid} PAID order(s) were still `pending` and were moved to `processing` for review."
            );
        }

        if ($unpaid > 0) {
            Log::info("remap_legacy_pending_order_status: {$unpaid} unpaid order(s) moved from `pending` to `cancelled`.");
        }

        // هر وضعیتِ یتیمِ دیگری که از دورهای قبل جا مانده باشد فقط لاگ می‌شود.
        // حدس‌زدنِ مقصدش داده را خراب می‌کند؛ ولی سکوت هم همان چیزی بود که این
        // ردیف‌ها را تا اینجا رساند.
        $known = array_map(fn (OrderStatus $c) => $c->value, OrderStatus::cases());
        $orphans = DB::table('orders')->whereNotIn('status', $known)->distinct()->pluck('status');

        if ($orphans->isNotEmpty()) {
            Log::warning('remap_legacy_pending_order_status: order statuses still outside the enum: '.$orphans->implode(', '));
        }
    }

    /**
     * برگشت عمداً هیچ کاری نمی‌کند — به همان دلیلِ مهاجرتِ قبلی: نگاشت یک‌طرفه
     * است و از روی `cancelled` نمی‌شود فهمید کدام ردیف از `pending` آمده.
     */
    public function down(): void {}
};
