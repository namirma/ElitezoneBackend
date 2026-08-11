<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `users.coupon_enabled` — معیارِ حالتِ `selected` در تنظیمِ `coupon_visibility`.
 *
 * سند بازطراحی این ستون را کنار ستون‌های بدهی (بند ۴.۷) فهرست کرده، ولی ربطی
 * به بدهی ندارد: تنها مصرف‌کننده‌اش `SalesSettings::couponVisibleFor()` است که
 * همین فاز ساخته می‌شود. گذاشتنش در فاز ۹ یعنی یک گترِ نیمه‌کاره که در حالت
 * `selected` همیشه false برمی‌گرداند، پس همراه خودِ گتر می‌آید.
 *
 * `null` یعنی «پیروی از تنظیم سراسری» و به همین دلیل nullable است — سه‌حالته
 * بودنش عمدی است: صراحتاً روشن / صراحتاً خاموش / تصمیم‌نگرفته.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'coupon_enabled')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('coupon_enabled')->nullable()->after('is_active')
                ->comment('null = پیروی از تنظیم سراسری coupon_visibility');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'coupon_enabled')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('coupon_enabled');
        });
    }
};
