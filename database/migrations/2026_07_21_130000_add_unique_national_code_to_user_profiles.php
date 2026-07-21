<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * کد ملی (national_code) کلیدِ احراز هویتِ شخصِ حقیقی است اما هیچ unique روی آن نبود،
 * پس دو حساب می‌توانستند با یک کد ملی ثبت شوند (بردار تقلب/دور زدن KYC — یافتهٔ CC-19).
 *
 * این مایگریشن «دفاعی» است: اگر همین حالا مقادیرِ تکراری (شاملِ رشتهٔ خالی) وجود داشته
 * باشد، افزودنِ unique شکست می‌خورد و کلِ deploy را می‌شکست. برای رعایتِ «چیزی خطا نده»،
 * ابتدا تکراری‌ها را می‌شماریم؛ اگر تکراری بود، فقط یک هشدار لاگ می‌کنیم و رد می‌شویم
 * (بدون افزودن index) تا مایگریشن هرگز crash نکند. پس از پاک‌سازیِ داده‌ها می‌توان دوباره
 * اجرا کرد. اگر داده تمیز بود، unique اضافه می‌شود.
 */
return new class extends Migration
{
    private const INDEX = 'user_profiles_national_code_unique';

    public function up(): void
    {
        if (! Schema::hasTable('user_profiles') || ! Schema::hasColumn('user_profiles', 'national_code')) {
            return;
        }

        // اگر قبلاً unique اضافه شده، کاری نکن (idempotent).
        if ($this->indexExists(self::INDEX)) {
            return;
        }

        $duplicates = DB::table('user_profiles')
            ->select('national_code', DB::raw('COUNT(*) as c'))
            ->whereNotNull('national_code')
            ->groupBy('national_code')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($duplicates > 0) {
            Log::warning(
                'Skipped adding unique index on user_profiles.national_code: '.
                "{$duplicates} duplicate value(s) exist. Clean the data, then re-run this migration."
            );

            return;
        }

        Schema::table('user_profiles', function ($table) {
            $table->unique('national_code', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_profiles') || ! $this->indexExists(self::INDEX)) {
            return;
        }

        Schema::table('user_profiles', function ($table) {
            $table->dropUnique(self::INDEX);
        });
    }

    private function indexExists(string $index): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'user_profiles')
            ->where('index_name', $index)
            ->exists();
    }
};
