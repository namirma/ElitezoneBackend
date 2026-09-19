<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اسنپ‌شاتِ دقیقِ سهم‌های OW و CO از %TC روی سفارش — همان الگوی `commission_rate`.
 *
 * از `a411c4f` سهم‌ها در پنلِ ادمین قابلِ ویرایش‌اند. باسکول تا این تغییر فقط %TC را
 * از اسنپ‌شات می‌خواند و سهم‌ها را زنده؛ پس ویرایشِ سهمِ OW بینِ پرداخت و باسکول POOM
 * را با وزنِ کاملاً یکسان از POO دور می‌کرد و پول جابه‌جا می‌شد.
 *
 * ستون‌های موجودِ `owner_discount_rate` / `elite_yar_share_rate` نرخِ نهایی را با
 * `decimal(5,2)` نگه می‌دارند (۰٫۳۷۵ → ۰٫۳۸) و برای بازسازیِ دقیق کافی نیستند؛ این دو
 * ستون خودِ درصدها را با چهار رقمِ اعشار نگه می‌دارند.
 *
 * ‏nullable عمداً: `null` یعنی «پرداخت‌شده پیش از این تغییر» و
 * `SettlementCalculatorService::getCommissionRatesForOrder` آن را با پیش‌فرض‌های
 * کاتالوگ — همان اعدادِ ثابتِ پیشین — حساب می‌کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'ow_share_percent')) {
            return;
        }

        Schema::table('orders', function (Blueprint $t) {
            $t->decimal('ow_share_percent', 7, 4)->nullable()->after('elite_yar_share_rate');
            $t->decimal('co_share_percent', 7, 4)->nullable()->after('ow_share_percent');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'ow_share_percent')) {
            return;
        }

        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn(['ow_share_percent', 'co_share_percent']);
        });
    }
};
