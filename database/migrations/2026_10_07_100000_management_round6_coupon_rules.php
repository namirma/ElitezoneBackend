<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مدیریت، دورِ ششم (۱۴۰۵/۰۷/۱۵) — `docs/session/MANAGEMENT_ANSWERS_2026-10-07.md`.
 *
 *  پرسشِ ۴ «فقط کیف پول؛ بودجه‌ی کمپین حذف شود»: «چندبارمصرف» تا امروز فقط از وجودِ بودجه خوانده
 *  می‌شد؛ ستونِ صریحِ `is_multi_use` می‌گیرد و از کوپن‌های بودجه‌دارِ موجود پر می‌شود. سقفِ بودجه پشتِ
 *  `features.campaign_budget` (پیش‌فرض خاموش) می‌رود؛ ستون‌های بودجه می‌مانند (قانونِ یک).
 *
 *  پرسشِ ۱ «فقط شرکت کوپن صادر می‌کند»: کوپن‌های فعالِ استفاده‌نشده‌ای که سهمی به تأمین‌کننده یا الیت‌یار
 *  دارند غیرفعال می‌شوند تا پنل‌ها «فعال» نشانشان ندهند (در پرداخت هم رد می‌شوند). شناسه‌ها در
 *  `round6_deactivations` ثبت می‌شوند و `down()` دقیقاً همان‌ها را برمی‌گرداند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->boolean('is_multi_use')->default(false)->after('budget_used');
        });

        DB::table('coupons')->whereNotNull('budget_total')->update(['is_multi_use' => true]);

        Schema::create('round6_deactivations', function (Blueprint $table) {
            $table->id();
            $table->string('table_name', 64);
            $table->unsignedBigInteger('row_id');
            $table->timestamp('created_at')->nullable();
        });

        $partnerCoupons = DB::table('coupons')
            ->where('is_active', true)
            ->whereNull('used_at')
            ->whereIn('id', DB::table('coupon_funders')->where('funder_type', '!=', 'company')->select('coupon_id'))
            ->pluck('id');

        foreach ($partnerCoupons as $id) {
            DB::table('round6_deactivations')->insert(['table_name' => 'coupons', 'row_id' => $id, 'created_at' => now()]);
        }

        DB::table('coupons')->whereIn('id', $partnerCoupons)->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        foreach (DB::table('round6_deactivations')->get() as $row) {
            DB::table($row->table_name)->where('id', $row->row_id)->update(['is_active' => true]);
        }

        Schema::dropIfExists('round6_deactivations');

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('is_multi_use');
        });
    }
};
