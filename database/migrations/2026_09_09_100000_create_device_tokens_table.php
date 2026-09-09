<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اشتراکِ پوشِ یک دستگاه (Web Push — استانداردِ RFC 8030/8291).
 *
 * ## چرا Web Push و نه یک پروویدرِ ایرانی
 *
 * برنامهٔ اپ، PWA است. مرورگر خودش سه چیز می‌دهد: `endpoint` (آدرسِ سرویسِ
 * پوشِ همان مرورگر)، و دو کلیدِ `p256dh`/`auth` برای رمزگذاری. سرور مستقیم به
 * همان endpoint پست می‌کند — نه حسابِ گوگل لازم است، نه SDK، نه قراردادِ
 * فروشنده. اگر بعداً APK نیتیو با SDKِ پوشه/چابک ساخته شد، همین جدول ردیفِ
 * نیتیو را هم نگه می‌دارد (کلیدها خالی) و مسیرِ ارسالش جدا اضافه می‌شود.
 *
 * ## چرا `endpoint` یکتاست و مالکش جابه‌جا می‌شود
 *
 * اشتراک به **دستگاه/مرورگر** تعلق دارد، نه به کاربر. اگر کاربر A خارج شود و
 * کاربر B روی همان دستگاه وارد شود، مرورگر همان endpoint را می‌دهد. اگر ردیفِ
 * قبلی بماند، نوتیفیکیشن‌های A روی دستگاهِ B ظاهر می‌شود — نشتِ اطلاعات، نه
 * صرفاً آزار. پس ثبتِ دوباره مالک را **بازنویسی** می‌کند.
 *
 * ## چرا ۵۱۲ کاراکتر
 *
 * endpointِ کروم حدود ۱۹۰ کاراکتر است و فایرفاکس ۱۵۰، ولی WNS (اج) تا حدود
 * ۳۵۰ هم می‌رود. ۲۵۵ برای اج تنگ است. ۵۱۲ در utf8mb4 برابر ۲۰۴۸ بایت است و
 * زیر سقفِ ۳۰۷۲ بایتیِ ایندکسِ InnoDB جا می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('device_tokens')) {
            return;
        }

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('endpoint', 512)->unique();

            // کلیدهای رمزگذاریِ اشتراک (RFC 8291). برای ردیفِ نیتیوِ آینده خالی
            // می‌مانند، پس nullable — نه چون مرورگر ممکن است ندهد.
            $table->string('public_key', 255)->nullable();  // p256dh
            $table->string('auth_key', 100)->nullable();    // auth

            // android | ios | web — فقط توصیفی: مسیرِ ارسال را وجودِ کلیدها
            // تعیین می‌کند، نه این ستون.
            $table->string('platform', 20);

            $table->string('app_version', 20)->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            // مسیرِ داغ: «همهٔ اشتراک‌های این کاربر» در هر ارسالِ پوش.
            $table->index('user_id', 'device_tokens_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
