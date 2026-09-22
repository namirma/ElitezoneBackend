<?php

namespace App\Services\Profile;

use App\Models\Setting;

/**
 * کدام روش‌های تأیید الیت یار روشن‌اند — تنظیمِ ادمین، نه ثابتِ کد.
 *
 * سه روش وجود دارد: مدارکِ تحصیلی و سوابقِ شغلی (هر دو با بررسیِ ادمین و
 * دکمه‌ی «تأیید مدارک») و عضویتِ سازه ۹۰ (خودکار، از saze90.com). مدیریت در
 * ۱۴۰۵/۰۶/۳۰ خواست برای لانچ **فقط** سازه ۹۰ روشن باشد و دو روشِ دیگر روی
 * سایت «به‌زودی» بمانند — بدون حذفِ زیرساختشان. پس روشن/خاموشیِ هر روش یک
 * کلید در جدولِ `settings` است (بک‌آفیس › تنظیمات › تأیید الیت یار) تا
 * برگرداندنشان دو کلیک باشد، نه یک دیپلوی.
 *
 * مصرف‌کننده‌ها: `User::isVerifiedEliteYAR()` (تنها محمولِ «تأییدشده»)،
 * میدل‌ورِ `verification.method` روی اندپوینت‌های ثبتِ مدرک/سابقه،
 * `GET /settings/public › elite_yar_verification` برای سایت، و
 * `AdminUserController::verifyEliteYAR` (تأییدِ دستی فقط وقتی یکی از دو
 * روشِ مدرکی روشن است).
 *
 * ⚠️ همیشه از همین کلاس بخوانید، نه مستقیم از `Setting` — پیش‌فرضِ کلیدِ
 * غایب همین‌جاست و فقط همین‌جا.
 */
final class EliteYARVerificationMethods
{
    public const EDUCATION = 'education';

    public const JOB = 'job';

    public const SAZE90 = 'saze90';

    /** روش → کلیدِ جدولِ settings (در `config/settings.php → allowed_keys` هم ثبت شده). */
    public const KEYS = [
        self::EDUCATION => 'elite_yar_verify_education',
        self::JOB => 'elite_yar_verify_job',
        self::SAZE90 => 'elite_yar_verify_saze90',
    ];

    /**
     * سیاستِ لانچ — وقتی ردیفی در جدول نباشد.
     *
     * مایگریشنِ `seed_elite_yar_verification_method_settings` همین مقادیر را
     * می‌کارد؛ این‌جا فقط پشتیبانِ آن است (دیتابیسی که آن مایگریشن را ندارد).
     */
    public const DEFAULTS = [
        self::EDUCATION => false,
        self::JOB => false,
        self::SAZE90 => true,
    ];

    /**
     * @return array{education: bool, job: bool, saze90: bool}
     */
    public static function enabled(): array
    {
        $stored = Setting::allCached();

        $out = [];
        foreach (self::KEYS as $method => $key) {
            $raw = $stored[$key] ?? null;

            // رشته‌ی خالی/غایب = پیش‌فرض؛ هر چیزِ دیگر فقط با «1» روشن است
            // (اعتبارسنجیِ `in:0,1` در PUT جلوی مقدارِ سومی را می‌گیرد).
            $out[$method] = ($raw === null || $raw === '')
                ? self::DEFAULTS[$method]
                : (string) $raw === '1';
        }

        return $out;
    }

    public static function isEnabled(string $method): bool
    {
        return self::enabled()[$method] ?? false;
    }

    /** آیا حداقل یکی از دو روشِ مدرکی (که با دکمه‌ی ادمین تأیید می‌شوند) روشن است؟ */
    public static function documentReviewEnabled(): bool
    {
        $enabled = self::enabled();

        return $enabled[self::EDUCATION] || $enabled[self::JOB];
    }

    /**
     * قواعدِ اعتبارسنجیِ همین کلیدها برای `PUT /admin/settings` — هم‌الگو با
     * `SalesSettings::validationRules()`.
     *
     * @return array<string, array<int, string>>
     */
    public static function validationRules(): array
    {
        return array_fill_keys(array_values(self::KEYS), ['in:0,1']);
    }
}
