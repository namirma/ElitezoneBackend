<?php

namespace Tests\Concerns;

use App\Models\User;
use Database\Seeders\AdminPermissionSeeder;
use Laratrust\Models\Permission;

/**
 * دادنِ همهٔ مجوزهای user-level به یک ادمینِ تستی.
 *
 * `EnsureAdminHasPermission` از نسخهٔ fail-closed به بعد، ادمینِ بدون مجوز را
 * ۴۰۳ می‌کند. در پروداکشن هر ادمین مجوزهایش را از `AdminPermissionSeeder` یا از
 * `AdminManagerController::store` (که پیش‌فرضش «همه» است) می‌گیرد؛ تست‌هایی که
 * ادمین را دستی با `addRole('admin')` می‌سازند باید همان کار را بکنند، وگرنه
 * دارند رفتاری را تست می‌کنند که هیچ ادمین واقعی‌ای ندارد.
 */
trait GrantsAdminPermissions
{
    protected function grantAllAdminPermissions(User $user): User
    {
        $ids = [];

        foreach (AdminPermissionSeeder::PERMISSIONS as $name => $displayName) {
            $ids[] = Permission::firstOrCreate(
                ['name' => $name],
                ['display_name' => $displayName],
            )->id;
        }

        $user->syncPermissionsWithoutDetaching($ids);

        // سطحِ اختیار هم مثلِ مجوزها: `EnsureAdminHasLevel` هم fail-closed است، پس
        // ادمینی که ستونش NULL بماند روی هر مسیرِ سطح‌دار ۴۰۳ می‌گیرد. هر ادمینِ
        // واقعی سطح دارد (مایگریشنِ موجودها را سطح ۱ کرد و ساختِ ادمینِ جدید سطح
        // می‌گیرد)، پس ادمینِ تستیِ بدونِ سطح حالتی را تست می‌کند که در عمل وجود
        // ندارد — دقیقاً همان استدلالی که بالا برای مجوزها آمده.
        return $this->setAdminLevel($user, User::ADMIN_LEVEL_OWNER);
    }

    /**
     * سطحِ اختیارِ یک ادمینِ تستی را تعیین می‌کند.
     *
     * برای تست‌هایی که باید *ردشدن* را ببینند (مثلاً کارشناسی که به مسیرِ مالی
     * می‌رسد) صریحاً سطحِ پایین‌تر پاس دهید.
     */
    protected function setAdminLevel(User $user, int $level): User
    {
        $user->forceFill([User::ADMIN_LEVEL => $level])->save();

        return $user->refresh();
    }
}
