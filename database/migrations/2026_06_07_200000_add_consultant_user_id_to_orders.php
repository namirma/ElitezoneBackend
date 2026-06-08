<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جداسازی مفهوم «مشاور فنی» (consultant) از «گیرنده» (beneficiary):
 *
 *   beneficiary_user_id (موجود) = کارفرمای گیرنده — فقط وقتی مشاور برای کارفرما می‌خرد
 *   consultant_user_id  (جدید)  = مشاور فنی که کمیسیون CO می‌گیرد
 *
 * در فلوی قبلی beneficiary_user_id هر دو نقش را داشت. این میگریشن آن را تفکیک می‌کند.
 * کلیه ردیف‌های موجود: مقدار فعلی beneficiary_user_id به consultant_user_id کپی می‌شود
 * تا داده‌های قدیمی از دست نروند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('consultant_user_id')
                ->nullable()
                ->after('beneficiary_user_id')
                ->comment('مشاور فنی که کمیسیون CO را دریافت می‌کند');

            $table->foreign('consultant_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        // داده‌های قدیمی: beneficiary_user_id (که در گذشته نقش مشاور داشت)
        // به consultant_user_id کپی می‌شود تا بدون از دست دادن اطلاعات مهاجرت کنیم.
        // پس از migration این ستون باید با منطق جدید (role-based validation) پر شود.
        DB::statement('
            UPDATE orders
            SET consultant_user_id = beneficiary_user_id
            WHERE beneficiary_user_id IS NOT NULL
              AND beneficiary_user_id != placer_user_id
        ');
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['consultant_user_id']);
            $table->dropColumn('consultant_user_id');
        });
    }
};
