<?php

use App\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * نگاشتِ هفت وضعیتِ حذف‌شده به جانشین‌هایشان (بند ۵.۲ سند بازطراحی).
 *
 * بند ۰ سند می‌گوید در محیط عملیاتی هیچ سفارش واقعی ثبت نشده، پس این مهاجرت
 * برای پروداکشن یک no-op است. ولی دیتابیس‌های dev/staging ردیف دارند و بدون
 * این نگاشت، آن ردیف‌ها به یک وضعیتِ یتیم می‌افتند که **هیچ پارتیشنی پوششش
 * نمی‌دهد**: `OrderBuilder::current/delivered/cancelled` هر سه ردش می‌کنند، پس
 * سفارش از هر سه تبِ «سفارش‌های من» ناپدید می‌شود؛ `orders:cancel-expired`
 * پیدایش نمی‌کند، پس هولدِ رزروش برای همیشه گیر می‌افتد؛ و `status_info.label`
 * به «نامشخص» می‌افتد. هیچ‌کدام خطا نمی‌دهند — فقط بی‌صدا غلط‌اند.
 *
 * سوئیت تست این را نمی‌گیرد: روی دیتابیسِ تازه با DatabaseTransactions اجرا
 * می‌شود و هرگز ردیفِ قدیمی نمی‌بیند.
 *
 * نگاشت‌ها:
 *   reserved_extended       → reserved    (تمدید دیگر وضعیت جدا نمی‌سازد)
 *   preparing               → processing  (ادغام، بند ۳.۶)
 *   weighbridge_done        → shipped     (باسکول حالا بخشی از ثبت بارنامه است)
 *   waybill_issued          → shipped
 *   pending_surplus_payment → awaiting_weight_correction
 *   completed               → delivered   (وضعیت پایانیِ واقعی)
 *   failed                  → cancelled
 */
return new class extends Migration
{
    private const MAP = [
        'reserved_extended' => Order::STATUS_RESERVED,
        'preparing' => Order::STATUS_PROCESSING,
        'weighbridge_done' => Order::STATUS_SHIPPED,
        'waybill_issued' => Order::STATUS_SHIPPED,
        'pending_surplus_payment' => Order::STATUS_AWAITING_WEIGHT_CORRECTION,
        'completed' => Order::STATUS_DELIVERED,
        'failed' => Order::STATUS_CANCELLED,
    ];

    public function up(): void
    {
        foreach (self::MAP as $old => $new) {
            $count = DB::table('orders')->where('status', $old)->update(['status' => $new]);

            if ($count > 0) {
                Log::info("remap_deleted_order_statuses: {$count} order(s) moved from `{$old}` to `{$new}`.");
            }
        }
    }

    /**
     * برگشت عمداً هیچ کاری نمی‌کند.
     *
     * نگاشت یک‌طرفه است: `weighbridge_done` و `waybill_issued` هر دو به `shipped`
     * می‌روند، پس از روی نتیجه نمی‌شود فهمید کدام ردیف از کدام آمده. حدس‌زدنش
     * داده را خراب می‌کند، و خودِ وضعیت‌های مقصد همگی معتبرند — پس برگشتِ
     * اسکیمای این مهاجرت چیزی برای انجام‌دادن ندارد.
     */
    public function down(): void {}
};
