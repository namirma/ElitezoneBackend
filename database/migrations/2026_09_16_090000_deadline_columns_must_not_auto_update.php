<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مهلت‌ها با هر UPDATE عوض می‌شدند — QA 2026-09-15: «`respond_until` بعد از
 * تصمیمِ ادمین ۵ ساعت عقب می‌رود».
 *
 * ریشه کد نبود، DDL بود: در MariaDB با `explicit_defaults_for_timestamp=0`
 * (پیش‌فرضِ XAMPP) **اولین** ستونِ `TIMESTAMP NOT NULL` جدول خودبه‌خود
 * `DEFAULT current_timestamp() ON UPDATE current_timestamp()` می‌گیرد. پس
 * `$table->timestamp('respond_until')` یعنی هر `save()` بعدی (ردِ طرف مقابل،
 * تصمیم ادمین) ستون را به «همین حالا» به وقتِ سرورِ دیتابیس (SYSTEM) می‌نوشت —
 * همان −۵ ساعت نسبت به UTC لاراول.
 *
 * `DATETIME` نه پیش‌فرضِ خودکار دارد نه تبدیلِ منطقه‌ی زمانی؛ مقدارهای موجود
 * بی‌تغییر منتقل می‌شوند. سه ستونِ همین دام در ریپو: `respond_until`،
 * `proposed_until` (همان نشست) و `user_agreements.accepted_at` (قدیمی‌تر، همان الگو).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_cancel_requests', function (Blueprint $table) {
            $table->dateTime('respond_until')->change();
        });

        Schema::table('order_extension_offers', function (Blueprint $table) {
            $table->dateTime('proposed_until')->change();
        });

        Schema::table('user_agreements', function (Blueprint $table) {
            $table->dateTime('accepted_at')->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_cancel_requests', function (Blueprint $table) {
            $table->timestamp('respond_until')->change();
        });

        Schema::table('order_extension_offers', function (Blueprint $table) {
            $table->timestamp('proposed_until')->change();
        });

        Schema::table('user_agreements', function (Blueprint $table) {
            $table->timestamp('accepted_at')->change();
        });
    }
};
