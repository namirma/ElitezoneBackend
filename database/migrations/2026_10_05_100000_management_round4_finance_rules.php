<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پاسخ‌های مدیریت به «پرسش‌های دورِ چهارم» (۱۴۰۵/۰۷/۱۳) —
 * `docs/session/MANAGEMENT_ANSWERS_2026-10-05.md`.
 *
 *  پرسش ۴  — `supplier_profiles.commission_percent`: درصدِ کارمزدِ **قراردادِ هر
 *             تأمین‌کننده**؛ NULL = همان مقدارِ سراسریِ `price_settings.tc_percentage`.
 *  پرسش ۶  — `orders.supplier_price`: قیمتِ ثبت‌شده‌ی تأمین‌کننده (میانگینِ وزنی،
 *             پیش از فیلترهای نقش‌محور) — مبنای PTS. `official_price` مبنای خریدار
 *             می‌ماند و برای این‌که نمایش و کسر تا ریال یکی باشند چهار رقمِ اعشار
 *             می‌گیرد (گردکردنِ هر کیلو تا ۰٫۵ ریال × وزن، روی ۱۰ تن ۵٬۰۰۰ ریال اختلاف
 *             می‌ساخت — همان چیزی که `OrderPricingBasisTest` برایش آستانه گذاشته بود).
 *  پرسش ۲/۷ — `orders.buyer_discount_amount`: مبلغِ تخفیفِ ویژه‌ی خریدار (سهمِ خریدار از
 *             کارمزد) که از این پس یک سطرِ جدا در سفارش و فاکتور است و فقط با الیت‌یار
 *             داده می‌شود. `final_amount` از این پس **همان مبلغِ کسرشده** است.
 *  پرسش ۱۰ — `coupons.budget_total` / `budget_used`: بودجه‌ی کمپین؛ بودجه‌دار یعنی
 *             چندمصرفه تا پایانِ بودجه.
 *  پرسش ۱۴ — `orders.admin_note`: توضیحِ آزادِ بک‌آفیس روی سفارش.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_profiles', function (Blueprint $table) {
            $table->decimal('commission_percent', 5, 2)->nullable()->after('trans_vat')
                ->comment('درصد کارمزد قرارداد این تأمین‌کننده؛ NULL = مقدار سراسری tc_percentage (مدیریت، پرسش ۴، ۱۴۰۵/۰۷/۱۳)');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('official_price', 20, 4)->nullable()->change();
            $table->decimal('supplier_price', 20, 4)->nullable()->after('official_price')
                ->comment('قیمت ثبت‌شده‌ی تأمین‌کننده (میانگین وزنی، پیش از فیلترهای نقش) — مبنای PTS (مدیریت، پرسش ۶)');
            $table->decimal('buyer_discount_amount', 20, 0)->default(0)->after('coupon_discount_amount')
                ->comment('تخفیف ویژه‌ی خریدار (سهم خریدار از کارمزد) — سطر جدا؛ فقط با الیت‌یار (مدیریت، پرسش ۲ و ۷)');
            $table->text('admin_note')->nullable()->after('notes')
                ->comment('توضیح بک‌آفیس روی سفارش؛ خریدار و تأمین‌کننده می‌بینند، خالی = نمایش نمی‌شود (مدیریت، پرسش ۱۴)');
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->decimal('budget_total', 20, 0)->nullable()->after('amount')
                ->comment('بودجه‌ی کل کمپین به ریال؛ NULL = کوپن تک‌مصرفه‌ی معمولی (مدیریت، پرسش ۱۰)');
            $table->decimal('budget_used', 20, 0)->default(0)->after('budget_total')
                ->comment('سهم شرکت از تخفیف‌های اعمال‌شده تا امروز (ریال)');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn(['budget_total', 'budget_used']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['supplier_price', 'buyer_discount_amount', 'admin_note']);
            $table->decimal('official_price', 20, 0)->nullable()->change();
        });

        Schema::table('supplier_profiles', function (Blueprint $table) {
            $table->dropColumn('commission_percent');
        });
    }
};
