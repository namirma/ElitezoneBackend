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

        return $user;
    }
}
