<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `user_profiles.national_code` هم NOT NULL بود هم UNIQUE.
 *
 * پیامدش: ساختن مدیر از پنل («مدیران سیستم») فقط **یک بار** کار می‌کرد.
 * `AdminManagerController::store()` برای عبور از NOT NULL مقدار `''` می‌نوشت،
 * و ایندکس UNIQUE رشته‌ی خالی را یک مقدارِ واقعی می‌شمارد — پس مدیر دومی که
 * ساخته می‌شد `SQLSTATE[23000] Duplicate entry ''` می‌گرفت (۵۰۰).
 *
 * یک عارضه‌ی دوم هم داشت: `ProcessPodiumDepositAction` با
 * `User::where('national_code', $nationalCode)` جست‌وجو می‌کند؛ یک استعلامِ
 * با کد ملیِ خالی می‌توانست به همان حسابِ مدیر تطبیق داده شود.
 *
 * مدیرِ داخلی واقعاً کد ملی ندارد، پس مقدار درست NULL است نه `''` —
 * MySQL در ایندکس UNIQUE چند NULL را می‌پذیرد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('national_code', 16)->nullable()->change();
        });

        // رشته‌های خالیِ موجود → NULL، وگرنه ایندکس UNIQUE همان‌طور مسدود می‌ماند.
        DB::table('user_profiles')->where('national_code', '')->update(['national_code' => null]);
    }

    public function down(): void
    {
        // بازگشت: NULL ها باید مقداری بگیرند وگرنه NOT NULL شدن fail می‌کند.
        // یک مقدار یکتا از روی id ساخته می‌شود تا ایندکس UNIQUE نشکند.
        DB::table('user_profiles')->whereNull('national_code')->update([
            'national_code' => DB::raw("CONCAT('x', id)"),
        ]);

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('national_code', 16)->nullable(false)->change();
        });
    }
};
