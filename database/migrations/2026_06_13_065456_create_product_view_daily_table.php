<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول تجمیعِ روزانه‌ی بازدید محصول.
 *
 * داده‌ی خامِ product_views پس از retention_days حذف می‌شود؛ این جدول شمارش‌های
 * تجمیعی را بلندمدت نگه می‌دارد تا کوئری‌های آنالیتیکس روی raw data (که با رشد جدول
 * کند و سنگین می‌شوند) اجرا نشوند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_view_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->date('view_date');
            $table->unsignedInteger('views')->default(0);           // کل بازدیدها (پس از dedup/bot-filter)
            $table->unsignedInteger('unique_sessions')->default(0); // session/کاربر یکتا
            $table->timestamps();

            // هر محصول در هر روز یک ردیف — upsert بر همین کلید.
            $table->unique(['product_id', 'view_date'], 'pvd_product_date_unique');
            $table->index('view_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_view_daily');
    }
};
