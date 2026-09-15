<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پیامک‌های سیستم، به‌عنوان رکورد — نه فقط رشته‌های `lang/fa/sms.php`.
 *
 * `sms_templates`: یک ردیف به‌ازای هر کلیدِ lang (built-in) به‌علاوه‌ی
 * قالب‌های سفارشیِ ادمین که به یک رویدادِ فهرست‌شده (`event`) بسته می‌شوند.
 * متنِ ردیف بر متنِ lang مقدم است؛ نبودِ ردیف یعنی متنِ lang (پس تا پیش از
 * seed هیچ رفتاری عوض نمی‌شود).
 *
 * `sms_logs`: هر تلاشِ ارسال با نتیجه‌اش، تا پنل بتواند «پیامک‌های ارسالی» را
 * نشان دهد — از جمله آن‌هایی که به‌خاطر allowlistِ محیطِ توسعه نرفته‌اند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('title', 150);
            $table->text('trigger_description')->nullable();
            $table->string('audience', 30)->default('any');
            $table->text('body');
            $table->json('placeholders')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_protected')->default(false);
            $table->json('conditions')->nullable();
            $table->string('event', 191)->nullable();
            $table->boolean('is_custom')->default(false);
            $table->timestamps();

            $table->index(['event', 'audience', 'is_active']);
        });

        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->string('mobile', 20)->index();
            $table->string('template_key', 100)->nullable()->index();
            $table->text('body');
            $table->string('status', 30)->index();
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
        Schema::dropIfExists('sms_templates');
    }
};
