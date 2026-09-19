<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * پر کردنِ `orders.official_price` برای سفارش‌هایی که هنوز تسویه نشده‌اند.
 *
 * تسویه با `official_price × weight_before_scale` کار می‌کند، ولی تا ۱۴۰۵/۰۵/۰۶
 * هیچ‌کس این ستون را نمی‌نوشت — هر سه مسیر روی فالبکِ `items->first()->unit_price`
 * می‌افتادند و کلِ تناژ به قیمتِ ردیفِ اول حساب می‌شد. `PlaceOrderAction` حالا
 * میانگینِ وزنی را اسنپ‌شات می‌کند؛ این مهاجرت همان کار را برای ردیف‌های موجود
 * انجام می‌دهد: `Σ(order_items.total_price) / weight_before_scale`.
 *
 * ⚠️ چرا فقط سفارش‌های **پرداخت‌نشده**
 * ─────────────────────────────────────
 * `WeighbridgeReconciler` مقدار این ستون را **هنگام باسکول** می‌خواند، در حالی که
 * POO هنگام **پرداخت** حساب و در `payment_of_owner` اسنپ‌شات شده است.
 *
 * پس اگر سفارشی را که پرداخت شده ولی هنوز توزین نشده backfill کنیم، POOM با مبنای
 * *جدید* و POO با مبنای *قدیم* حساب می‌شوند — دو عددِ ناهم‌خوان روی وزنِ یکسان.
 * `ProcessWeighbridgeResultAction` این اختلاف را «کسری» تفسیر می‌کند و شاخه‌ای را
 * فعال می‌کند که **واقعاً** پول به خریدار برمی‌گرداند و **واقعاً** بدهی برای
 * تأمین‌کننده ثبت می‌کند — بدون آنکه یک کیلوگرم کم آمده باشد. دقیقاً همان تله‌ای
 * که برای اسنپ‌شاتِ `%TC` مستند شده است.
 *
 * بنابراین شرط `payment_of_owner IS NULL OR <= 0` اختیاری نیست؛ **حذفش این مهاجرت
 * را به یک ابزارِ جابه‌جاییِ پول تبدیل می‌کند.**
 *
 * سفارش‌های پرداخت‌شده‌ی بدون اسنپ‌شات عمداً دست‌نخورده می‌مانند: آن‌ها با همان
 * فالبک تسویه شده‌اند و این واقعیتِ تاریخیِ آن‌هاست. `financial:doctor` همچنان
 * علامتشان می‌زند — که درست است، چون گزارشِ خسارت است نه نویز.
 *
 * مقادیرِ **غیرصفرِ** موجود هم بازنویسی نمی‌شوند؛ این مهاجرت فقط جای خالی را پر
 * می‌کند، تصمیمِ کسی را عوض نمی‌کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('order_items')) {
            return;
        }

        foreach (['official_price', 'weight_before_scale', 'payment_of_owner'] as $column) {
            if (! Schema::hasColumn('orders', $column)) {
                return;
            }
        }

        $affected = DB::table('orders')
            ->whereRaw('(official_price IS NULL OR official_price <= 0)')
            ->whereRaw('weight_before_scale > 0')
            // گاردِ حیاتی — توضیح کامل بالای همین فایل.
            ->whereRaw('(payment_of_owner IS NULL OR payment_of_owner <= 0)')
            ->whereRaw('(SELECT SUM(oi.total_price) FROM order_items oi WHERE oi.order_id = orders.id) > 0')
            ->update([
                'official_price' => DB::raw(
                    '(SELECT SUM(oi.total_price) FROM order_items oi WHERE oi.order_id = orders.id)'
                    .' / weight_before_scale'
                ),
            ]);

        Log::channel('financial')->info(
            "backfill_official_price_on_unsettled_orders: {$affected} unsettled order(s) received a weighted-average official_price."
        );
    }

    public function down(): void
    {
        // بازگشت عمداً بی‌عمل است. مقدارِ قبلی «خالی» بود و برگرداندنش یعنی
        // دوباره انداختنِ همان سفارش‌ها روی فالبکِ ردیفِ اول — یعنی بازتولیدِ
        // همان باگ. ستون هم پیش از این هیچ نویسنده‌ای نداشت، پس چیزی برای
        // احیا وجود ندارد.
    }
};
