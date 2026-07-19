<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserType;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * AdminSeeder — فقط یک کاربر ادمین برای پنل بک‌آفیس می‌سازد.
 *
 * اجرا:
 *   php artisan db:seed --class=AdminSeeder
 *
 * لاگین در بک‌آفیس: با «موبایل + رمز عبور» (POST /api/authenticate/password/check).
 *
 * idempotent است — می‌توانی چند بار اجرایش کنی؛ اگر کاربر باشد فقط رمز/نقش را
 * به‌روزرسانی می‌کند و کاربر تکراری نمی‌سازد.
 *
 * برای تغییر اطلاعات ورود، سه ثابتِ زیر را ویرایش کن.
 */
class AdminSeeder extends Seeder
{
    /** موبایل ادمین (کلید ورود) */
    private const MOBILE = '09100000009';

    /** رمز عبور ادمین — در محیط عمومی حتماً تغییر بده */
    private const PASSWORD = 'Admin@1234';

    /** کد ملی (برای کامل بودن پروفایل؛ لاگین با پسورد به آن نیاز دارد) */
    private const NATIONAL_CODE = '0010000009';

    public function run(): void
    {
        // ۱. نقشِ admin (Laratrust) — لازم برای عبور از middleware `role:admin`
        $adminRole = Role::firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'مدیر'],
        );

        // ۲. یک UserType معتبر — ستون users.type_id روی NOT NULL است
        $type = UserType::firstOrCreate(
            ['slug' => 'customer'],
            ['name' => 'مشتری', 'icon' => 'user'],
        );

        // ۳. کاربر ادمین — idempotent؛ رمز/کدملی/تاریخ تولد هر بار همگام می‌شود
        $admin = User::updateOrCreate(
            [User::MOBILE => self::MOBILE],
            [
                User::NATIONAL_CODE => self::NATIONAL_CODE,
                User::BIRTH_DATE    => '13700101',
                User::PASSWORD      => Hash::make(self::PASSWORD),
                User::TYPE_ID       => $type->id,
                User::IS_ACTIVE     => true,
            ],
        );

        // ۴. پروفایل (برای نمایش نام مدیر در پنل)
        UserProfile::firstOrCreate(
            [UserProfile::USER_ID => $admin->id],
            [
                UserProfile::NAME          => 'مدیر',
                UserProfile::FAMILY        => 'سیستم',
                UserProfile::NATIONAL_CODE => self::NATIONAL_CODE,
            ],
        );

        // ۵. کیف پول (تا endpointهایی که کیف پول را می‌خوانند به null نخورند)
        Wallet::firstOrCreate(
            [Wallet::USER_ID => $admin->id],
            [Wallet::BALANCE => 0, Wallet::BALANCE_HOLD => 0],
        );

        // ۶. اتصال نقشِ admin — idempotent
        if (! $admin->hasRole('admin')) {
            $admin->addRole($adminRole);
        }

        // ۷. نمایش اطلاعات ورود
        $this->command->info('');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('  ✅  کاربر ادمین آماده شد');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->table(
            ['موبایل', 'رمز عبور', 'نقش', 'ID'],
            [[self::MOBILE, self::PASSWORD, 'admin', $admin->id]],
        );
        $this->command->warn('  ⚠  رمز پیش‌فرض را در محیط عمومی حتماً تغییر بده.');
        $this->command->info('');
    }
}
