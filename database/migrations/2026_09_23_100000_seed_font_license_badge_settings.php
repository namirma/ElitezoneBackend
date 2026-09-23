<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * نشانِ لایسنسِ فونتِ یکان‌بخ در فوترِ سایت (علی، ۱۴۰۵/۰۷/۰۱).
 *
 * مجوزِ فونتِ خریداری‌شده از fontiran (شناسه‌ی SF4998) نمایشِ این نشان روی وب‌سایت
 * را خواسته است. مثلِ نمادهای اینماد و ساماندهی از «تنظیمات سیستم» مدیریت می‌شود
 * (قاعده‌ی طلایی)؛ این migration فقط مقدارِ اولیه را می‌کارد تا نشان بدونِ ورودِ
 * دستی روی هر محیطی بیاید. متنِ پیشنهادیِ fontiran «از فونت فونت یکان‌بخ» بود — با
 * «فونت»ِ تکراری؛ این‌جا اصلاح‌شده‌اش آمده است.
 *
 * مثلِ `seed_elite_yar_verification_method_settings`: اگر ادمین قبلاً مقدار داده، دست نمی‌خورد.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'font_license_image_url' => 'https://fontiran.com/storage/uploads/images/2023/1/badge4-minJZec6p.png',
        'font_license_link' => 'https://fontiran.com/license/SF4998',
        'font_license_text' => 'این وب‌سایت به صورت قانونی از فونت یکان‌بخ استفاده می‌کند.',
    ];

    public function up(): void
    {
        foreach (self::DEFAULTS as $key => $value) {
            if (DB::table('settings')->where(Setting::KEY, $key)->exists()) {
                continue;
            }
            DB::table('settings')->insert([
                Setting::KEY => $key,
                Setting::VALUE => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        // درجِ مستقیم از hookِ مدل نمی‌گذرد؛ کشِ تنظیمات را خودمان خالی می‌کنیم.
        Cache::forget(Setting::CACHE_KEY);
    }

    public function down(): void
    {
        DB::table('settings')->whereIn(Setting::KEY, array_keys(self::DEFAULTS))->delete();
        Cache::forget(Setting::CACHE_KEY);
    }
};
