<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * حذفِ نقشِ جعلیِ `customer` و معنادار کردنِ `users.type_id = NULL`.
 *
 * `customer` هرگز یک نقشِ محصول نبود؛ فقط برای پر کردنِ ستونِ NOT NULL هنگام
 * ثبت‌نام ساخته می‌شد. نتیجه این بود که «هنوز نقشی انتخاب نکرده» و «نقشش مشتری
 * است» از هم قابلِ تفکیک نبودند، و کاربری که از مرحله‌ی انتخابِ نقش رد می‌شد برای
 * همیشه در نقشی می‌ماند که در محصول وجود ندارد.
 *
 * بعد از این مهاجرت:
 *   type_id = NULL  →  هنوز نقشی انتخاب نکرده
 *   type_id ≠ NULL  →  نقشِ واقعی
 *
 * ── نسبتش با 2026_07_27_120000_converge_users_type_id_to_not_null ──
 *
 * آن مهاجرت این ستون را NOT NULL کرد و دلیلش واقعی بود: روی dev ستون nullable
 * بود و روی سرورِ تازه نه، پس `PlatformWalletUserSeeder` که `type_id` نمی‌داد
 * روی dev سبز بود و روی هر دیتابیسِ تازه کلِ `db:seed` را می‌خواباند.
 *
 * این مهاجرت آن واگرایی را برنمی‌گرداند، چون علتِ اصلی جای دیگری بود: کدی که
 * کاربر می‌ساخت `type_id` نمی‌داد و ستون هم پیش‌فرض نداشت. حالا هر سه نقطه
 * (`AuthController::register`، `PlatformWalletUserSeeder`، `UserFactory`)
 * صراحتاً `type_id = null` می‌گذارند، پس رفتار روی هر محیطی یکسان است — این بار
 * nullable بودن یک تصمیمِ ثبت‌شده در مهاجرت است، نه یک اختلافِ کشف‌نشده.
 *
 * idempotent است و ترتیبش مهم: اول ستون باز می‌شود، بعد ارجاع‌ها null می‌شوند،
 * و تنها در آخر ردیف حذف می‌شود — برعکسش به خطای کلیدِ خارجی می‌خورد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'type_id')) {
            return;
        }

        if (! $this->isNullable()) {
            DB::statement('ALTER TABLE `users` MODIFY `type_id` BIGINT UNSIGNED NULL');
        }

        $customerId = DB::table('user_types')->where('slug', 'customer')->value('id');

        if ($customerId === null) {
            return; // قبلاً حذف شده
        }

        DB::table('users')->where('type_id', $customerId)->update(['type_id' => null]);
        DB::table('user_types')->where('id', $customerId)->delete();
    }

    /**
     * برگشت عمداً ستون را دوباره NOT NULL نمی‌کند.
     *
     * برای NOT NULL کردن باید به هر کاربرِ بی‌نقش یک `type_id` نسبت داد، و تنها
     * گزینه ساختنِ دوباره‌ی همان ردیفِ جعلی است — یعنی همان حالتی که این مهاجرت
     * برای حذفش نوشته شده. ردیفِ `customer` بازسازی می‌شود تا داده‌ای که up()
     * پاک کرده قابلِ بازیابی باشد، ولی کاربرانِ null دست‌نخورده می‌مانند: کدام
     * کاربر واقعاً «مشتری» بوده و کدام هرگز نقشی انتخاب نکرده، بعد از up() قابلِ
     * تشخیص نیست و حدس زدنش داده را خراب می‌کند.
     */
    public function down(): void
    {
        if (DB::table('user_types')->where('slug', 'customer')->exists()) {
            return;
        }

        DB::table('user_types')->insert([
            'slug' => 'customer',
            'name' => 'مشتری',
            'icon' => 'user',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function isNullable(): bool
    {
        $row = DB::selectOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS'
            .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['users', 'type_id']
        );

        return $row !== null && strtoupper((string) $row->IS_NULLABLE) === 'YES';
    }
};
