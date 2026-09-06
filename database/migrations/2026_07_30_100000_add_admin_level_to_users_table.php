<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * سطحِ اختیارِ ادمین — لایه‌ی دوم، بالای مجوزها.
 *
 * تا امروز همه‌ی ادمین‌ها هم‌سطح بودند و تنها تفاوتشان مجوزها بود. مجوز به این
 * پرسش پاسخ می‌دهد که «به کدام بخش دسترسی دارد»، ولی به این یکی نه: «تا کجا حق
 * دارد». نتیجه‌اش این بود که هر ادمینی که مجوز manage-users داشت می‌توانست ادمین
 * بسازد، به خودش هر مجوزی بدهد و عملاً به سطحِ مالکِ برند برسد — یعنی مرزی برای
 * ارتقای خودخواسته وجود نداشت.
 *
 * سه سطح، هرکدام ابرمجموعه‌ی پایینی (۱ بالاترین):
 *
 *   ۱ مدیر ارشد — اختیارِ برند: ساخت/حذف ادمین، اعطای مجوز، تأیید تصویرِ ادمین‌ها،
 *                  فرمولِ تسویه‌ی تأمین‌کننده، تنظیماتِ هویتیِ سایت.
 *   ۲ مدیر      — عملیاتِ کامل شاملِ پول و قیمت: مالی، کیف پول، Podium، باسکول،
 *                  کوپن، مدیریتِ کاربران، کاتالوگ و قیمت، گزارش‌ها.
 *   ۳ کارشناس   — کارهای روزمره و برگشت‌پذیر: تیکت، محتوا، نماهای فقط‌خواندنی.
 *
 * ── درباره‌ی backfill ────────────────────────────────────────────────────
 * همه‌ی ادمین‌های موجود سطح ۱ می‌گیرند. این عمدی است و دقیقاً همان الگوی
 * `backfill_admin_permissions` را دنبال می‌کند: مایگریشن نباید رفتار را عوض کند،
 * فقط باید زیرساخت را اضافه کند. اگر به‌جایش حدس می‌زدیم چه کسی مالک است و بقیه
 * را تنزل می‌دادیم، ممکن بود مالکِ واقعی را از پنلِ خودش بیرون بیندازیم — خطایی
 * که از راهِ پنل قابلِ جبران نیست و به دیتابیس دست‌بردن می‌خواهد.
 *
 * تنزلِ بقیه به سطحِ درست، تصمیمِ انسانی است و از خودِ پنل انجام می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'admin_level')) {
            Schema::table('users', function (Blueprint $table) {
                // nullable چون کاربرِ عادی سطحِ ادمین ندارد — صفر یا هر مقدارِ
                // پیش‌فرضی اینجا یعنی «همه یک سطحی دارند» که غلط است.
                $table->unsignedTinyInteger('admin_level')
                    ->nullable()
                    ->after('is_active')
                    ->comment('1=ارشد 2=مدیر 3=کارشناس; null=ادمین نیست');
            });
        }

        $this->backfillExistingAdmins();
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'admin_level')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('admin_level');
            });
        }
    }

    /**
     * هر کاربری که نقشِ admin دارد سطح ۱ می‌گیرد — رفتارِ امروز بدونِ تغییر.
     *
     * از join روی جدولِ نقش‌های Laratrust استفاده می‌شود نه از مدل، چون مایگریشن
     * نباید به وضعیتِ لحظه‌ایِ کدِ اپلیکیشن وابسته باشد.
     */
    private function backfillExistingAdmins(): void
    {
        foreach (['roles', 'role_user'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');
        if (! $adminRoleId) {
            return;
        }

        DB::table('users')
            ->whereNull('admin_level')
            ->whereIn('id', function ($q) use ($adminRoleId) {
                $q->select('user_id')->from('role_user')->where('role_id', $adminRoleId);
            })
            ->update(['admin_level' => User::ADMIN_LEVEL_OWNER]);
    }
};
