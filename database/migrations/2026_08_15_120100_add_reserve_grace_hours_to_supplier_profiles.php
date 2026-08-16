<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `supplier_profiles.reserve_grace_hours` — الحاقیهٔ ۱، تغییر ۳.
 *
 * تصمیم تازهٔ مدیریت: پنج ساعت **پیش‌فرضِ بک‌آفیس** است و تأمین‌کننده می‌تواند
 * مدتِ ارفاق را خودش تنظیم کند. الگو دقیقاً همان `delivery_deadline_hours`:
 * پیش‌فرض بک‌آفیس → override تأمین‌کننده → اسنپ‌شات روی سفارش.
 *
 * `null` یعنی «پیروی از تنظیم بک‌آفیس» و پیش‌فرضِ صفر نیست — صفر یک انتخابِ
 * معتبر است («هیچ ارفاقی نده») و باید از «تصمیم نگرفته‌ام» قابل تفکیک بماند.
 *
 * در `$fillable` می‌نشیند، برخلاف `status`/`trans_*`: این را خودِ تأمین‌کننده
 * تنظیم می‌کند، نه ادمین.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('supplier_profiles', 'reserve_grace_hours')) {
            return;
        }

        Schema::table('supplier_profiles', function (Blueprint $table) {
            $table->unsignedInteger('reserve_grace_hours')->nullable()->after('reserve_auto_extend_minutes')
                ->comment('override تأمین‌کننده؛ null یعنی پیروی از تنظیم بک‌آفیس');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('supplier_profiles', 'reserve_grace_hours')) {
            return;
        }

        Schema::table('supplier_profiles', function (Blueprint $table) {
            $table->dropColumn('reserve_grace_hours');
        });
    }
};
