<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «تا تأیید نشده کالا موقتاً از کاتالوگ خارج می‌شود» (تصمیم مدیریت ۲۰۲۶-۰۹-۰۹،
 * بندِ چهارم — تنها بندی که `bb66c0d` انجام نداد).
 *
 * شناسه‌ی ردیفِ قیمت تا امروز فقط داخلِ `payload` (json) بود؛ برای پنهان‌کردنِ
 * واریانت در کوئریِ عمومی یک ستونِ واقعی و ایندکس‌دار لازم است، وگرنه هر
 * فهرستِ محصول باید json را باز کند. عمداً FK ندارد: حذفِ ردیفِ قیمت (تأمین‌کننده
 * کارخانه‌اش را برداشته) نباید سابقه‌ی درخواست را بشکند — گاردِ
 * `price_row_missing` موقعِ تأیید همان حالت را صریح می‌کند.
 *
 * ردیف‌های موجود از همان payload پر می‌شوند تا درخواست‌های بازِ امروز هم
 * کالایشان را پنهان کنند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('product_price_id')->nullable()->after('order_id');
            $table->index(['product_price_id', 'type', 'status'], 'supplier_requests_price_type_status_idx');
        });

        DB::table('supplier_requests')
            ->where('type', 'price_change')
            ->whereNotNull('payload')
            ->orderBy('id')
            ->each(function (object $row) {
                $payload = json_decode((string) $row->payload, true);
                $priceId = is_array($payload) ? ($payload['product_price_id'] ?? null) : null;

                if ($priceId !== null) {
                    DB::table('supplier_requests')
                        ->where('id', $row->id)
                        ->update(['product_price_id' => (int) $priceId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('supplier_requests', function (Blueprint $table) {
            $table->dropIndex('supplier_requests_price_type_status_idx');
            $table->dropColumn('product_price_id');
        });
    }
};
