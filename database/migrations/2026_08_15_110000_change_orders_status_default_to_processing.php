<?php

use App\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * پیش‌فرضِ ستون `orders.status` از `pending` به `processing`.
 *
 * `pending` در فاز ۴ حذف شد: سفارش دیگر پیش از نشستنِ پول ساخته نمی‌شود، پس
 * وضعیتی به معنای «ساخته شده ولی پرداخت‌نشده» وجود ندارد. مهاجرتِ اصلیِ جدول
 * هم به `Order::STATUS_PROCESSING` اشاره می‌کند تا نصبِ تازه نشکند؛ این
 * مهاجرت همان تغییر را روی دیتابیس‌هایی می‌آورد که از قبل ساخته شده‌اند.
 *
 * پیش‌فرض عملاً هرگز استفاده نمی‌شود — هر دو مسیرِ ساخت وضعیت را صریح می‌دهند —
 * ولی یک پیش‌فرضِ نامعتبر در اسکیما تله‌ای است برای هر درجِ مستقیمِ آینده.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `orders` MODIFY `status` VARCHAR(255) NOT NULL DEFAULT '".Order::STATUS_PROCESSING."'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `orders` MODIFY `status` VARCHAR(255) NOT NULL DEFAULT 'pending'");
    }
};
