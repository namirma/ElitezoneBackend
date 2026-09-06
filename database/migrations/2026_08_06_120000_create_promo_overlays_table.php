<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اورلی‌های ترغیب/تبلیغاتی — کاملاً مدیریت‌شده از بک‌آفیس.
 *
 * تا پیش از این، سه اورلیِ سایت (خوش‌آمد، تعیین نقش، تعیین الیت یار) کامپوننتِ
 * هاردکدِ React بودند: تغییرِ یک کلمه از متن هم نیاز به دیپلوی داشت و شرطِ
 * نمایششان در کد دفن بود. این جدول همان سه‌تا و هر اورلیِ جدیدی را داده می‌کند.
 *
 * سه ستونِ json عمداً json‌اند و نه جدولِ جدا:
 *   buttons  — آرایه‌ای کوچک و همیشه یکجا خوانده/نوشته می‌شود؛ هیچ کوئری‌ای
 *              روی تک‌تکِ دکمه‌ها زده نمی‌شود.
 *   audience — درختِ شرط با عمقِ متغیر (and/or)؛ نرمال‌سازی‌اش یعنی سه جدول
 *              برای چیزی که همیشه کامل خوانده می‌شود.
 *   routes   — دو آرایه‌ی الگوی مسیر (include/exclude).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_overlays', function (Blueprint $table) {
            $table->id();

            // کلیدِ پایدار برای سه اورلیِ سیستمی که seed می‌شوند. اورلی‌هایی که
            // ادمین می‌سازد `null` دارند. یکتا ولی nullable — MySQL چند NULL را
            // در ایندکسِ unique می‌پذیرد.
            $table->string('key')->nullable()->unique();

            $table->string('title');
            $table->text('body')->nullable();

            // مسیر تصویر روی دیسکِ public. اورلیِ بدون تصویر هم مجاز است.
            $table->string('image')->nullable();

            // [{label, style, action, value}] — واژگانِ action در مدل ثابت است.
            $table->json('buttons')->nullable();

            // درختِ شرطِ مخاطب: {"op":"and","rules":[{...}]}
            $table->json('audience')->nullable();

            // {"include":["*"],"exclude":["/role","/auth","/admin"]}
            $table->json('routes')->nullable();

            // چند ثانیه بعد از احرازِ شرایط نمایش داده شود
            $table->unsignedInteger('delay_seconds')->default(30);

            // once | day | session | ttl | always
            $table->string('frequency')->default('day');
            // فقط وقتی frequency = ttl
            $table->unsignedInteger('frequency_ttl_hours')->nullable();

            // وقتی چند اورلی هم‌زمان واجد شرایط‌اند، کوچک‌ترین priority برنده است
            $table->integer('priority')->default(0);

            $table->boolean('is_active')->default(true);

            $table->softDeletes();
            $table->timestamps();

            // پرس‌وجوی عمومی همیشه «فعال‌ها به ترتیب اولویت» است
            $table->index(['is_active', 'priority'], 'promo_overlays_active_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_overlays');
    }
};
