<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * آخرین باقی‌ماندهٔ نامِ قدیمیِ «مشاور فنی»: اسلاگِ `expert` در `user_types`.
 *
 * ستون‌ها و جدول‌ها قبلاً تغییر کرده بودند و برچسبِ فارسیِ همین ردیف هم از قبل
 * «الیت یار» بود — فقط خودِ اسلاگ مانده بود، و چون قراردادِ API آن را در
 * `GET /api/profile` (فیلدِ `type.slug`) و `GET /api/profile/role-options`
 * برمی‌گرداند، عوض‌کردنش سمتِ فرانت را هم می‌شکند. طبق درخواستِ تیمِ فرانت
 * هم‌زمان دیپلوی می‌شود.
 *
 * idempotent: اگر ردیفِ `elite_yar` از قبل باشد هیچ کاری نمی‌کند. ساختنِ ردیفِ
 * دوم بدترین حالتِ ممکن است — `type_id`های کاربران بین دو ردیف دوپاره می‌شود و
 * هر کوئریِ `whereIn(SLUG, [...])` نصفِ کاربران را از دست می‌دهد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('user_types')->where('slug', 'elite_yar')->exists()) {
            if (DB::table('user_types')->where('slug', 'expert')->exists()) {
                Log::warning(
                    'rename_expert_user_type_to_elite_yar: both `expert` and `elite_yar` rows exist.'
                    .' Refusing to merge automatically — user_types.type_id would have to be repointed'
                    .' and that is a data decision, not a schema one. Resolve by hand.'
                );
            }

            return;
        }

        DB::table('user_types')
            ->where('slug', 'expert')
            ->update(['slug' => 'elite_yar', 'name' => 'الیت یار']);
    }

    public function down(): void
    {
        if (DB::table('user_types')->where('slug', 'expert')->exists()) {
            return;
        }

        DB::table('user_types')
            ->where('slug', 'elite_yar')
            ->update(['slug' => 'expert', 'name' => 'الیت یار']);
    }
};
