<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * نقشِ Laratrust «elite_yar» + پر کردنِ آن برای کاربرانِ موجود.
 *
 * تا پیش از این، «الیت یار بودن» از دو جای مستقل خوانده می‌شد:
 * `users.type_id → user_types.slug = 'elite_yar'` یا داشتنِ نقشِ
 * `engineer`/`contractor`. آن OR یک ضرورت بود نه سلیقه — نقش‌های فنی فقط بعد از
 * تأیید مدارک وصل می‌شوند، پس الیت یارِ تازه هیچ نقشی نداشت.
 *
 * حالا `elite_yar` خودش یک نقش است و لحظه‌ی انتخابِ نقش وصل می‌شود، پس گاردها یک
 * سیگنال می‌خوانند. `engineer`/`contractor` سرِ جایشان می‌مانند و معنیِ دقیق‌شان
 * را پس می‌گیرند: «مدرکش تأیید شده»، نه «الیت یار است».
 *
 * ── چرا backfill حیاتی است ──
 * `EnsureUserIsEliteYAR` در همین تغییر به `hasRole('elite_yar')` ساده می‌شود.
 * بدون این backfill، همه‌ی الیت یارهای موجود در لحظه‌ی دیپلوی روی کلِ مسیرهای
 * `role.elite_yar` (مدارک تحصیلی، سوابق شغلی، دعوتِ کارفرما) ۴۰۳ می‌گیرند.
 *
 * idempotent: هم ساختِ نقش و هم اتصالش با بررسیِ وجود انجام می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', 'elite_yar')->value('id');

        if ($roleId === null) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => 'elite_yar',
                'display_name' => 'الیت یار',
                'description' => 'الیت یار — با انتخابِ نقش وصل می‌شود؛ مستقل از تأییدِ مدارک.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // معیار دقیقاً همان دو شرطی است که گاردِ قدیمی می‌پذیرفت، تا هیچ‌کس در
        // این جابه‌جایی دسترسی از دست ندهد و هیچ‌کس هم دسترسیِ تازه نگیرد.
        $eliteYarTypeId = DB::table('user_types')->where('slug', 'elite_yar')->value('id');

        $byType = $eliteYarTypeId === null
            ? []
            : DB::table('users')->where('type_id', $eliteYarTypeId)->pluck('id')->all();

        $byRole = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->whereIn('roles.name', ['engineer', 'contractor'])
            ->where('role_user.user_type', 'App\Models\User')
            ->pluck('role_user.user_id')
            ->all();

        $userIds = array_values(array_unique(array_merge($byType, $byRole)));

        if ($userIds === []) {
            return;
        }

        $already = DB::table('role_user')
            ->where('role_id', $roleId)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->all();

        $rows = [];

        foreach (array_diff($userIds, $already) as $userId) {
            $rows[] = [
                'role_id' => $roleId,
                'user_id' => $userId,
                'user_type' => 'App\Models\User',
            ];
        }

        if ($rows !== []) {
            DB::table('role_user')->insert($rows);
        }
    }

    /**
     * فقط اتصال‌ها برداشته می‌شوند و خودِ ردیفِ نقش می‌ماند.
     *
     * حذفِ ردیفِ `roles` یعنی هر ارجاعِ باقی‌مانده به این نام
     * `ModelNotFoundException` می‌دهد که هندلر به ۴۰۴ تبدیلش می‌کند — همان تله‌ی
     * مستندشده در SyncUserRolesAction::dropRole. یک ردیفِ بلااستفاده بی‌ضرر است.
     */
    public function down(): void
    {
        $roleId = DB::table('roles')->where('name', 'elite_yar')->value('id');

        if ($roleId !== null) {
            DB::table('role_user')->where('role_id', $roleId)->delete();
        }
    }
};
