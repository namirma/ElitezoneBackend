<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * batch_id برای ایدمپوتنت‌کردنِ ارسالِ گروهیِ اعلان (SendBulkNotificationJob).
 *
 * جابِ ارسالِ انبوه در صورتِ شکستِ میانه‌راه دوباره اجرا می‌شد و ردیف‌های قبلاً درج‌شده
 * را دوباره درج می‌کرد (اعلانِ تکراری). با batch_idِ ثابت به‌ازای هر dispatch و ایندکسِ
 * یکتای (user_id, batch_id) + insertOrIgnore، اجرای مجدد ردیف‌های تکراری را رد می‌کند و
 * فقط باقی‌مانده را درج می‌کند — بدون بلاک‌کردنِ ارسال‌های گروهیِ واقعاً مجزا (batch_id متفاوت).
 * batch_id nullable است تا رکوردهای قدیمی و اعلان‌های تکیِ غیرِ گروهی دست‌نخورده بمانند
 * (MySQL چند NULL را زیر unique می‌پذیرد).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_notifications') || Schema::hasColumn('user_notifications', 'batch_id')) {
            return;
        }

        Schema::table('user_notifications', function (Blueprint $table) {
            $table->uuid('batch_id')->nullable()->after('sender_id');
            $table->unique(['user_id', 'batch_id'], 'user_notifications_user_batch_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_notifications') || ! Schema::hasColumn('user_notifications', 'batch_id')) {
            return;
        }

        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropUnique('user_notifications_user_batch_unique');
            $table->dropColumn('batch_id');
        });
    }
};
