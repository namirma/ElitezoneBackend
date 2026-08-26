<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table) {
            // تعدادِ روزِ تخمینیِ تحویل — برای محاسبه‌ی «تاریخ تحویل» در صفحه‌ی
            // سفارش (امروز + این عدد). null یعنی هنوز تنظیم نشده؛ در آن حالت
            // فرانت تاریخِ تحویل را نشان نمی‌دهد (بدونِ عددِ جعلی).
            $table->unsignedInteger('delivery_days')->nullable()->after('delivery_notice');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table) {
            $table->dropColumn('delivery_days');
        });
    }
};
