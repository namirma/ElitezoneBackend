<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * هم‌تراز کردنِ `users.type_id` با تعریفِ مایگریشن‌ها.
 *
 * `2014_10_12_000000_create_users_table` این ستون را `foreignId('type_id')` تعریف
 * می‌کند — یعنی **NOT NULL**. ولی روی دیتابیسِ توسعه nullable بود، و این واگرایی
 * یک کلاسِ کاملِ باگ را نامرئی می‌کرد: کدی که کاربر را دستی می‌سازد و `type_id`
 * نمی‌دهد، روی آن ماشین کار می‌کند و روی سرورِ تازه با
 * «Field 'type_id' doesn't have a default value» می‌افتد.
 *
 * دقیقاً همین اتفاق افتاد: `PlatformWalletUserSeeder` روی توسعه سبز بود و چون
 * اولین ورودیِ `DatabaseSeeder` است، روی هر دیتابیسِ تازه کلِ `db:seed` را
 * می‌خواباند.
 *
 * این مهاجرت هر محیطی را به تعریفِ مایگریشن می‌رساند و **idempotent** است:
 *
 *  ۱. اگر ستون از قبل NOT NULL باشد، هیچ ALTER‌ای نمی‌زند — روی جدولِ بزرگِ
 *     `users` یک ALTER بی‌مورد یعنی قفلِ جدول بدون هیچ سودی.
 *  ۲. اگر ردیفی `type_id = NULL` داشته باشد، اول با همان نوعِ پیش‌فرضی که
 *     `UserFactory`/ثبت‌نام استفاده می‌کنند (`slug = customer`) پر می‌شود، وگرنه
 *     خودِ ALTER شکست می‌خورد.
 *  ۳. اگر آن نوع وجود نداشته باشد، مهاجرت **کاری نمی‌کند** و لاگ می‌زند — هرگز
 *     یک شناسه‌ی دلخواه نمی‌نویسد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'type_id')) {
            return;
        }

        if (! $this->isNullable()) {
            return; // از قبل هم‌تراز است — ALTER لازم نیست.
        }

        $orphans = DB::table('users')->whereNull('type_id')->count();

        if ($orphans > 0) {
            $customerTypeId = DB::table('user_types')->where('slug', 'customer')->value('id');

            if (! $customerTypeId) {
                Log::warning(
                    "converge_users_type_id: {$orphans} user(s) have a NULL type_id and no 'customer' UserType exists —"
                    .' leaving the column nullable rather than inventing an id. Run UserTypeSeeder, then re-run this migration.'
                );

                return;
            }

            DB::table('users')->whereNull('type_id')->update(['type_id' => $customerTypeId]);
        }

        DB::statement('ALTER TABLE `users` MODIFY `type_id` BIGINT UNSIGNED NOT NULL');
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'type_id')) {
            DB::statement('ALTER TABLE `users` MODIFY `type_id` BIGINT UNSIGNED NULL');
        }
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
