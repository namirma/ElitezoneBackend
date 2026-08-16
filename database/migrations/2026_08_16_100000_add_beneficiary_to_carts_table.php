<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سبد را به کارفرمایی که قرار است پولش را بدهد گره می‌زند — فاز ۶.
 *
 * تصمیم مدیریت (پرسش ۱، گزینه‌ی ب): لینک پرداخت روی **سبد** می‌نشیند، نه روی
 * سفارش. چون از بند ۳.۳ به بعد سفارش فقط وقتی ساخته می‌شود که پول نشسته باشد،
 * دیگر سفارشِ پرداخت‌نشده‌ای وجود ندارد که بتوان لینکش را فرستاد — پس خودِ سبد
 * تنها چیزی است که پیش از پرداخت وجود دارد.
 *
 * `payment_link_sent_at` فقط برای این است که پنل الیت‌یار بتواند بگوید «لینک
 * فرستاده شد» و ارسالِ دوباره را محدود کند؛ اعتبارِ لینک از این ستون خوانده
 * نمی‌شود — آن `carts.parked_until` است (تصمیم پرسش ۴: اعتبار تا Reserve Time).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->foreignId('beneficiary_user_id')
                ->nullable()
                ->after('supplier_user_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('payment_link_sent_at')->nullable()->after('beneficiary_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropForeign(['beneficiary_user_id']);
            $table->dropColumn(['beneficiary_user_id', 'payment_link_sent_at']);
        });
    }
};
