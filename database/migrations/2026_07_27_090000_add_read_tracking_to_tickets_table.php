<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ردیابیِ «خوانده‌شده» برای تیکت‌ها.
 *
 * تا امروز هیچ ستونی برای این وجود نداشت، پس نه پنل می‌توانست بگوید کدام تیکت
 * پیام تازه دارد و نه کاربر می‌فهمید پشتیبانی جواب داده است — هر دو طرف باید
 * تک‌تک تیکت‌ها را باز می‌کردند تا بفهمند چیزی عوض شده یا نه.
 *
 * چرا دو ستونِ ساده و نه یک جدولِ per-user:
 * میزِ پشتیبانی یک صف مشترک است؛ «آخرین باری که *یک* ادمین این تیکت را باز کرد»
 * همان چیزی است که برای نشان‌دادن «پیام جدید» لازم است. جدولِ خواندهٔ per-admin
 * هزینهٔ نگهداری و کوئری دارد بدون اینکه تصمیمِ متفاوتی ممکن کند.
 * ⚠️ پیامدش را بدانید: اگر ادمین A تیکت را باز کند، برای ادمین B هم خوانده
 * حساب می‌شود. اگر روزی «صندوقِ شخصیِ هر ادمین» لازم شد، این باید جدول شود.
 *
 * سمت کاربر تک‌مالکی است (`tickets.user_id`)، پس آنجا یک ستون دقیقاً درست است.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('admin_last_read_at')->nullable()->after('escalated_at');
            $table->timestamp('user_last_read_at')->nullable()->after('admin_last_read_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['admin_last_read_at', 'user_last_read_at']);
        });
    }
};
