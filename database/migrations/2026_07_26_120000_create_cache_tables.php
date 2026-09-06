<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول‌های کشِ دیتابیسی — لازم برای قفلِ *مشترکِ بین سرورها*.
 *
 * میدل‌ور Idempotency روی `Cache::lock()` تکیه می‌کند و درایور پیش‌فرض `file` است.
 * قفلِ فایلی per-server است: با دو سرور اپلیکیشن پشت لودبالانسر، دو درخواستِ
 * هم‌زمانِ پرداخت روی دو سرور مختلف هر دو قفل را می‌گیرند و گاردِ مالی عملاً
 * بی‌اثر می‌شود. کشِ پاسخ هم همین‌طور: سرور A پاسخ را کش می‌کند و سرور B آن را
 * نمی‌بیند، پس محافظتِ ضدِ تکرار هم از بین می‌رود.
 *
 * دیتابیس ذاتاً بین همهٔ سرورهای اپلیکیشن مشترک است، پس این جدول‌ها مسئله را
 * بدون نیاز به زیرساختِ Redis حل می‌کنند (Redis همچنان گزینهٔ سریع‌تر است —
 * IDEMPOTENCY_CACHE_STORE=redis).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }

        // نامِ `cache_locks` قراردادیِ لاراول است (`lock_table` در config/cache.php
        // تنظیم نشده، پس DatabaseStore همین پیش‌فرض را می‌خواهد).
        if (! Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
    }
};
