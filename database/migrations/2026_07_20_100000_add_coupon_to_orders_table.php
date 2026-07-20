<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اتصال کوپن به سفارش — کوپن اعمال‌شده و مبلغ تخفیف در لحظه ثبت snapshot می‌شود.
 * تخفیف به صورت یک leg مجزای کیف پول (WSSG→WO) هنگام پرداخت اعمال می‌شود؛
 * فرمول‌های POO/PTS دست نمی‌خورند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('discount_amount')
                ->constrained('coupons')->nullOnDelete();
            $table->decimal('coupon_discount_amount', 18, 2)->default(0)->after('coupon_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn('coupon_discount_amount');
        });
    }
};
