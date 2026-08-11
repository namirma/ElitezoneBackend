<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * سبدِ مهلت‌دار (Park) — بند ۳.۱ و ۴.۱ سند بازطراحی.
 *
 * سبد جای رکوردِ `Order` با وضعیت `pending` را می‌گیرد: افزودن کالا دیگر هیچ
 * سفارشی نمی‌سازد و موجودی کسر نمی‌کند، ولی سبد بی‌نهایت هم زنده نمی‌ماند.
 *
 * `supplier_user_id` و `address_id` روی **سبد** می‌نشینند و نه روی ردیف: هر سبد
 * فقط یک تأمین‌کننده و هر سفارش فقط یک مقصد دارد. ستون‌های هم‌نامِ
 * `cart_items` فعلاً سرِ جایشان می‌مانند و در فاز پاک‌سازی (فاز ۱۴) حذف
 * می‌شوند — مهاجرت‌ها افزایشی‌اند.
 *
 * ایندکس روی `parked_until` برای دو کرونی است که هر دقیقه/هر پنج دقیقه کلِ
 * جدول را جارو می‌کنند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            if (! Schema::hasColumn('carts', 'parked_until')) {
                $table->timestamp('parked_until')->nullable()->after('badge_id')
                    ->comment('پایان مهلت نگهداری سبد؛ null یعنی سبد خالی یا مهمان');
            }
            if (! Schema::hasColumn('carts', 'park_warned_at')) {
                $table->timestamp('park_warned_at')->nullable()->after('parked_until')
                    ->comment('زمان ارسال پیامک هشدار؛ برای جلوگیری از ارسال دوباره');
            }
            if (! Schema::hasColumn('carts', 'supplier_user_id')) {
                $table->unsignedBigInteger('supplier_user_id')->nullable()->after('park_warned_at')
                    ->comment('تأمین‌کننده‌ی قفل‌شده‌ی سبد؛ افزودن کالای تأمین‌کننده‌ی دیگر مجاز نیست');
            }
            if (! Schema::hasColumn('carts', 'address_id')) {
                $table->unsignedBigInteger('address_id')->nullable()->after('supplier_user_id')
                    ->comment('مقصد واحد سبد');
            }
            if (! Schema::hasColumn('carts', 'coupon_code')) {
                $table->string('coupon_code', 100)->nullable()->after('address_id')
                    ->comment('فقط کد؛ مبلغ در لحظه‌ی رزرو/پرداخت محاسبه می‌شود (بند ۳.۱۳)');
            }
            if (! Schema::hasColumn('carts', 'shipping_method_id')) {
                $table->unsignedBigInteger('shipping_method_id')->nullable()->after('coupon_code');
            }
        });

        Schema::table('carts', function (Blueprint $table) {
            if (! $this->hasIndex('carts', 'carts_parked_until_idx')) {
                $table->index('parked_until', 'carts_parked_until_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            if ($this->hasIndex('carts', 'carts_parked_until_idx')) {
                $table->dropIndex('carts_parked_until_idx');
            }
        });

        Schema::table('carts', function (Blueprint $table) {
            foreach (['parked_until', 'park_warned_at', 'supplier_user_id', 'address_id', 'coupon_code', 'shipping_method_id'] as $column) {
                if (Schema::hasColumn('carts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * وجودِ ایندکس با `SHOW INDEX` و نه doctrine/dbal — آن پکیج در لاراول ۱۱ به
     * بعد وابستگیِ پیش‌فرض نیست و این مهاجرت باید روی هر دو شاخه اجرا شود.
     */
    private function hasIndex(string $table, string $index): bool
    {
        return DB::select('SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?', [$index]) !== [];
    }
};
