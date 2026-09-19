<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SupplierProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * فلگ‌های ±TC/±VAT را برای رزروهای **باز** از قراردادِ تأمین‌کننده پر می‌کند.
 *
 * ‏`ProcessReservationPaymentAction` از این پس این دو را اسنپ‌شات می‌کند و
 * `ProcessReservationCompletionAction` فقط اسنپ‌شات را می‌خواند. رزروهایی که پیش از
 * این تغییر ثبت شده‌اند مقدارِ پیش‌فرضِ ستون (`true`) را دارند و بدون این مهاجرت با
 * فرمولِ +TC/+VAT تکمیل می‌شدند — همان باگ.
 *
 * ## چرا فقط `reserved` و چرا این امن است
 *
 * از `fd82956` (معرفیِ فلگ‌ها) تا امروز تنها نویسنده‌های `orders.trans_*`
 * `ProcessPreScalePaymentAction` و `ProcessReservationCompletionAction` بوده‌اند و
 * هر دو در **همان** نوشتن وضعیت را `processing` می‌کنند. پس ردیفی که هنوز `reserved`
 * است هرگز این ستون‌ها را نوشته‌شده نداشته و `true`ِ آن به‌طور اثبات‌پذیر پیش‌فرضِ
 * ستون است، نه یک تصمیم. مقدارِ جایگزین همان چیزی است که فالبکِ `??` تکمیل قصد
 * داشت بخواند (پروفایلِ زنده).
 *
 * ردیف‌های پرداخت‌شده عمداً دست نمی‌خورند: `true`ِ آن‌ها قابل تفکیک نیست (ممکن است
 * قراردادِ واقعی باشد) و POO با همان فرمول از خریدار گرفته شده؛ عوض کردنِ فلگ الان
 * POOM باسکول را در وزنِ یکسان از POO جدا می‌کند و یک کسریِ کاذب می‌سازد.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fixed = 0;

        DB::table('orders')
            ->where(Order::STATUS, Order::STATUS_RESERVED)
            ->select('id')
            ->chunkById(200, function ($orders) use (&$fixed) {
                foreach ($orders as $order) {
                    $supplierId = DB::table('order_items')
                        ->where(OrderItem::ORDER_ID, $order->id)
                        ->orderBy('id')
                        ->value(OrderItem::SUPPLIER_USER_ID);

                    $profile = $supplierId === null ? null : DB::table('supplier_profiles')
                        ->where(SupplierProfile::USER_ID, $supplierId)
                        ->first([SupplierProfile::TRANS_COMMISSION, SupplierProfile::TRANS_VAT]);

                    // بدونِ پروفایل یا با قراردادِ +TC/+VAT، پیش‌فرضِ ذخیره‌شده همان جوابِ درست است.
                    if ($profile === null || ((bool) $profile->trans_commission && (bool) $profile->trans_vat)) {
                        continue;
                    }

                    // شرطِ وضعیت دوباره روی UPDATE — اگر رزرو بین SELECT و اینجا تکمیل شد، دست نخورد.
                    $fixed += DB::table('orders')
                        ->where('id', $order->id)
                        ->where(Order::STATUS, Order::STATUS_RESERVED)
                        ->update([
                            Order::TRANS_COMMISSION => (bool) $profile->trans_commission,
                            Order::TRANS_VAT => (bool) $profile->trans_vat,
                        ]);
                }
            });

        if ($fixed > 0) {
            Log::channel('financial')->warning(
                "snapshot_trans_flags_on_open_reservations: {$fixed} open reservation(s) now carry their supplier's -TC/-VAT contract."
            );
        }
    }

    /**
     * برگشت عمداً کاری نمی‌کند — بازگرداندنِ `true` همان باگ را برمی‌گرداند.
     */
    public function down(): void {}
};
