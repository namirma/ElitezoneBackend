<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مدیریت، دورِ هفتم، پرسشِ ۷-۲ (۱۴۰۵/۰۷/۱۵) — `docs/session/MANAGEMENT_ANSWERS_2026-10-07_ROUND7.md`:
 * افزایشِ قیمتِ نقش‌محور هم «خاموش شود؛ قیمتِ فروش برای همه برابرِ قیمتِ تأمین‌کننده».
 *
 * کد دیگر هیچ فیلتری اعمال نمی‌کند (`features.role_price_filters`)؛ این مایگریشن داده را با آن هم‌خوان می‌کند
 * تا پنل فیلترِ «فعال»ی نشان ندهد که اثری ندارد. فقط فیلترهایی که واقعاً قیمت را عوض می‌کردند (مبلغِ غیرصفر)
 * غیرفعال می‌شوند؛ شناسه‌شان در `round7_deactivations` می‌ماند و `down()` دقیقاً همان‌ها را برمی‌گرداند —
 * هیچ حذفی (قانونِ یک).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('round7_deactivations', function (Blueprint $table) {
            $table->id();
            $table->string('table_name', 64);
            $table->unsignedBigInteger('row_id');
            $table->timestamp('created_at')->useCurrent();
        });

        $filters = DB::table('price_filters')->where('is_active', true)->where('amount', '<>', 0)->pluck('id');

        foreach ($filters as $id) {
            DB::table('round7_deactivations')->insert(['table_name' => 'price_filters', 'row_id' => $id]);
        }

        DB::table('price_filters')->whereIn('id', $filters)->update(['is_active' => false, 'updated_at' => now()]);

        // DB::table رویدادهای مدل را رد می‌کند؛ کشِ فیلترها (ApplyPriceFilters) دستی خالی می‌شود.
        Cache::forget('pricing:categories_with_filters');
    }

    public function down(): void
    {
        $ids = DB::table('round7_deactivations')->where('table_name', 'price_filters')->pluck('row_id');

        DB::table('price_filters')->whereIn('id', $ids)->update(['is_active' => true, 'updated_at' => now()]);

        Schema::dropIfExists('round7_deactivations');

        Cache::forget('pricing:categories_with_filters');
    }
};
