<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * توسعه‌ی توافق‌نامه‌ها طبق «دستورالعمل پیاده‌سازی توافق‌نامه آنلاین الیت یار»:
 *
 * agreements:
 *   - title           عنوان نمایشی صفحه پذیرش
 *   - checkboxes      چک‌باکس‌های الزامی نسخه (JSON: [{code, text}])
 *   - content_sha256  هش SHA-256 متن نسخه — پذیرش به متنِ دقیق گره می‌خورد
 *   - effective_at    تاریخ لازم‌الاجرا شدن نسخه
 *
 * user_agreements (append-only — هرگز update/delete نشود):
 *   - version / content_sha256   نسخه و هشِ همان لحظه‌ی پذیرش (نه آخرین نسخه)
 *   - checked_items              کدهای چک‌باکس پذیرفته‌شده + متن همان زمان
 *   - acceptance_method          روش پذیرش (checkbox+otp_login و…)
 *   - snapshot                   اسنپ‌شات کامل پذیرش (شماره موبایل تأییدشده، UA و…)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agreements', function (Blueprint $table) {
            $table->string('title')->nullable()->after('slug');
            $table->json('checkboxes')->nullable()->after('content');
            $table->string('content_sha256', 64)->nullable()->after('checkboxes');
            $table->timestamp('effective_at')->nullable()->after('content_sha256');
        });

        Schema::table('user_agreements', function (Blueprint $table) {
            $table->string('version', 20)->nullable()->after('accepted_at');
            $table->string('content_sha256', 64)->nullable()->after('version');
            $table->json('checked_items')->nullable()->after('user_agent');
            $table->string('acceptance_method', 60)->nullable()->after('checked_items');
            $table->json('snapshot')->nullable()->after('acceptance_method');
        });
    }

    public function down(): void
    {
        Schema::table('user_agreements', function (Blueprint $table) {
            $table->dropColumn(['version', 'content_sha256', 'checked_items', 'acceptance_method', 'snapshot']);
        });

        Schema::table('agreements', function (Blueprint $table) {
            $table->dropColumn(['title', 'checkboxes', 'content_sha256', 'effective_at']);
        });
    }
};
