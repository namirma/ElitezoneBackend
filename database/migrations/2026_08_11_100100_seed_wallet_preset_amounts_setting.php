<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * مبالغ پیشنهادی شارژ کیف پول تا امروز در فرانت هاردکد بود
 * (PRESET_AMOUNTS در ProfileWalletDepositRequest.jsx).
 *
 * همان سه مقدار این‌جا کاشته می‌شود تا سایت دقیقاً همان رفتار امروز را داشته
 * باشد؛ از این به بعد ادمین از «تنظیمات سیستم» عوضشان می‌کند.
 */
return new class extends Migration
{
    private const KEY = 'wallet_preset_amounts';

    private const VALUE = '100000000,500000000,1000000000';

    public function up(): void
    {
        // updateOrInsert تا اجرای دوباره‌ی مایگریشن روی دیتابیسی که ادمین
        // مقدارش را عوض کرده، تغییرِ او را برنگرداند.
        if (! DB::table('settings')->where(Setting::KEY, self::KEY)->exists()) {
            DB::table('settings')->insert([
                Setting::KEY => self::KEY,
                Setting::VALUE => self::VALUE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->where(Setting::KEY, self::KEY)->delete();
    }
};
