<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * محرکِ هر پیامک به‌عنوان داده‌ی ساخت‌یافته، نه فقط یک شرحِ آزاد.
 *
 * تا پیش از این built-inها فقط `trigger_description` داشتند و `event` خالی بود؛
 * پنل نمی‌توانست بگوید «این پیامک روی کدام رویداد، از کدام کلاس، یا با کدام
 * زمان‌بندی می‌رود». سه ستونِ nullable — ردیفِ موجود بدونِ seed معنایش عوض نمی‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_templates', function (Blueprint $table) {
            // event | action | scheduled | none (متن آماده است ولی فرستنده‌ای ندارد)
            $table->string('trigger_kind', 20)->nullable()->after('event');
            // کلاس/متدِ فرستنده یا نامِ دستورِ کرون — کدِ لاتین، برای ردگیری
            $table->string('sender', 191)->nullable()->after('trigger_kind');
            // زمان‌بندی به زبانِ آدم («هر ۵ دقیقه»)؛ فقط برای scheduled یا اقدامی که کرون هم دارد
            $table->string('schedule', 100)->nullable()->after('sender');
        });
    }

    public function down(): void
    {
        Schema::table('sms_templates', function (Blueprint $table) {
            $table->dropColumn(['trigger_kind', 'sender', 'schedule']);
        });
    }
};
