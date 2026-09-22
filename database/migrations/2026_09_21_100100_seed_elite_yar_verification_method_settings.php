<?php

use App\Models\Setting;
use App\Services\Profile\EliteYARVerificationMethods;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * سه کلیدِ «روش‌های تأیید الیت یار» با سیاستِ لانچ کاشته می‌شوند
 * (تصمیمِ مدیریت ۱۴۰۵/۰۶/۳۰: فقط عضویتِ سازه ۹۰؛ تحصیلی و شغلی «به‌زودی»).
 *
 * چرا کاشتن و نه فقط پیش‌فرضِ کد: فرمِ تنظیماتِ بک‌آفیس مقدارِ ذخیره‌شده را
 * نشان می‌دهد؛ کلیدِ غایب آن‌جا «خالی» دیده می‌شد و ادمین نمی‌فهمید وضعیتِ
 * واقعیِ سایت چیست. پیش‌فرضِ کد (`EliteYARVerificationMethods::DEFAULTS`)
 * فقط پشتیبانِ همین ردیف‌هاست.
 *
 * مثلِ `seed_wallet_preset_amounts`: اگر ادمین قبلاً مقدار داده، دست نمی‌خورد.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (EliteYARVerificationMethods::KEYS as $method => $key) {
            if (DB::table('settings')->where(Setting::KEY, $key)->exists()) {
                continue;
            }

            DB::table('settings')->insert([
                Setting::KEY => $key,
                Setting::VALUE => EliteYARVerificationMethods::DEFAULTS[$method] ? '1' : '0',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // درجِ مستقیم از hookِ مدل نمی‌گذرد، پس کشِ یک‌ساعته‌ی تنظیمات را خودمان
        // خالی می‌کنیم — وگرنه تا یک ساعت بعد از دیپلوی، سایت کلیدها را نمی‌دید.
        Cache::forget(Setting::CACHE_KEY);
    }

    public function down(): void
    {
        DB::table('settings')->whereIn(Setting::KEY, array_values(EliteYARVerificationMethods::KEYS))->delete();
        Cache::forget(Setting::CACHE_KEY);
    }
};
