<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Laratrust\Models\Permission;

/**
 * مجوزهای سطح‌بندی پنل ادمین.
 *
 * idempotent — چندبار اجرا امن است. همه مجوزها به ادمین‌های موجود (user-level)
 * داده می‌شود تا هیچ ادمین فعلی دسترسی از دست ندهد؛ محدودسازی از این به بعد
 * از طریق AdminManagerController (پارامتر permissions) انجام می‌شود.
 */
class AdminPermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'manage-financial' => 'مدیریت مالی (کیف پول، واریزها، گزارش مالی)',
        'manage-content' => 'مدیریت محتوا (بنر، مقاله، FAQ، صفحات، تنظیمات، اعلان)',
        'manage-users' => 'مدیریت کاربران و مدیران',
        'manage-orders' => 'مدیریت سفارش‌ها و باسکول',
        'manage-catalog' => 'مدیریت کاتالوگ (محصول، قیمت، فیلتر قیمت)',
        'manage-tickets' => 'مدیریت تیکت‌ها',
    ];

    public function run(): void
    {
        $permissions = [];
        foreach (self::PERMISSIONS as $name => $displayName) {
            $permissions[] = Permission::firstOrCreate(
                ['name' => $name],
                ['display_name' => $displayName],
            );
        }

        User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->get()
            ->each(fn (User $admin) => $admin->syncPermissionsWithoutDetaching(
                collect($permissions)->pluck('id')->all()
            ));
    }
}
