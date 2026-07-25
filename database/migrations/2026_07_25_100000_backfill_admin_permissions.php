<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * پشتیبانِ یک‌بارهٔ fail-closed شدنِ EnsureAdminHasPermission.
 *
 * تا پیش از این، «ادمینی که هیچ مجوز user-level ندارد» در میدل‌ور به معنیِ
 * «دسترسی کامل» تفسیر می‌شد. این قاعده وارونهٔ نیتِ AdminManagerController::update
 * بود: ارسال permissions=[] برای سلبِ دسترسی، عملاً دسترسیِ کامل (از جمله
 * manage-financial → تسویهٔ بانکی و شارژ دستیِ کیف‌پول) می‌داد.
 *
 * میدل‌ور حالا fail-closed است، پس سازگاریِ عقب‌رو باید یک‌بار به‌صورت داده‌ای
 * انجام شود: هر ادمینی که امروز صفر مجوز دارد (یعنی تا دیروز دسترسیِ کامل داشت)
 * صریحاً همهٔ مجوزها را می‌گیرد تا هیچ ادمین فعالی در لحظهٔ دیپلوی قفل نشود.
 *
 * ایمنی:
 *   - idempotent — اجرای مجدد چیزی را تغییر نمی‌دهد.
 *   - فقط ادمین‌هایی که *صفر* مجوز دارند لمس می‌شوند؛ هر ادمینی که قبلاً
 *     سطح‌بندی شده دست‌نخورده می‌ماند (مجوزهایش گسترش داده نمی‌شود).
 *   - down() عمداً no-op است — بازگرداندنِ مجوزها به «صفر» یعنی قفل‌کردنِ
 *     ادمین‌ها، که بدتر از حالت فعلی است.
 */
return new class extends Migration
{
    /**
     * باید با AdminPermissionSeeder::PERMISSIONS یکی بماند. اینجا کپی شده تا
     * مایگریشن به کلاسِ seeder (که ممکن است بعداً تغییر کند) وابسته نباشد —
     * مایگریشن باید همان چیزی را اجرا کند که در لحظهٔ نوشتنش درست بوده.
     */
    private const PERMISSIONS = [
        'manage-financial' => 'مدیریت مالی (کیف پول، واریزها، گزارش مالی)',
        'manage-content' => 'مدیریت محتوا (بنر، مقاله، FAQ، صفحات، تنظیمات، اعلان)',
        'manage-users' => 'مدیریت کاربران و مدیران',
        'manage-orders' => 'مدیریت سفارش‌ها و باسکول',
        'manage-catalog' => 'مدیریت کاتالوگ (محصول، قیمت، فیلتر قیمت)',
        'manage-tickets' => 'مدیریت تیکت‌ها',
    ];

    public function up(): void
    {
        // روی نصبِ تازه ممکن است هنوز جدول‌های laratrust ساخته نشده باشند.
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('permission_user')
            || ! Schema::hasTable('roles') || ! Schema::hasTable('role_user')) {
            return;
        }

        $permissionIds = [];
        foreach (self::PERMISSIONS as $name => $displayName) {
            $id = DB::table('permissions')->where('name', $name)->value('id');

            if ($id === null) {
                $id = DB::table('permissions')->insertGetId([
                    'name' => $name,
                    'display_name' => $displayName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $permissionIds[] = $id;
        }

        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');

        if ($adminRoleId === null) {
            return;
        }

        // user_type از خودِ role_user خوانده می‌شود تا مایگریشن به نامِ کلاسِ
        // مدل (و به تنظیمِ laratrust.use_morph_map) گره نخورد.
        $admins = DB::table('role_user')
            ->where('role_id', $adminRoleId)
            ->get(['user_id', 'user_type']);

        foreach ($admins as $admin) {
            $hasAny = DB::table('permission_user')
                ->where('user_id', $admin->user_id)
                ->where('user_type', $admin->user_type)
                ->exists();

            // ادمینِ سطح‌بندی‌شده را دست نمی‌زنیم — فقط آن‌هایی که تا امروز
            // از قاعدهٔ «صفر مجوز = دسترسی کامل» استفاده می‌کردند.
            if ($hasAny) {
                continue;
            }

            $rows = array_map(fn ($permissionId) => [
                'permission_id' => $permissionId,
                'user_id' => $admin->user_id,
                'user_type' => $admin->user_type,
            ], $permissionIds);

            DB::table('permission_user')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        // no-op عمدی: سلبِ مجوزها ادمین‌ها را قفل می‌کند.
    }
};
