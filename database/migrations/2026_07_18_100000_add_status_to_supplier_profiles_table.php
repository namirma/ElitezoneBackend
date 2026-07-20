<?php

use App\Models\SupplierProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * وضعیت احراز سوپلایر — نقش supplier دیگر خود-انتسابی نیست.
 *
 * انتخاب نقش توسط کاربر فقط پروفایل را با وضعیت pending می‌سازد؛ نقش Laratrust
 * (که روی قیمت‌گذاری و فلگ‌های فرمول تسویه اثر دارد) تنها پس از تأیید ادمین
 * (AdminSupplierController::approve) متصل می‌شود.
 *
 * Backfill: پروفایل‌های موجود approved می‌شوند تا سوپلایرهای فعلی (که نقش را
 * قبل از این گیت گرفته‌اند) بدون وقفه به کارشان ادامه دهند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_profiles', function (Blueprint $table) {
            $table->string('status', 20)
                ->default(SupplierProfile::STATUS_PENDING)
                ->after('trans_vat')
                ->comment('وضعیت احراز سوپلایر: pending | approved | rejected');
        });

        DB::table('supplier_profiles')->update(['status' => SupplierProfile::STATUS_APPROVED]);
    }

    public function down(): void
    {
        Schema::table('supplier_profiles', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
