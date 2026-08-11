<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonInterval;

/**
 * تنها راهِ خواندنِ پارامترهای مسیر فروش.
 *
 * هر گتر ابتدا جدول `settings` را می‌بیند (کش‌شده در `Setting::allCached()`) و
 * اگر کلید نبود به `config/sales.php` برمی‌گردد. دامنه هرگز نباید مستقیم
 * `config('sales.*')` بخواند — آن مسیر تغییراتِ بک‌آفیس را نمی‌بیند.
 *
 * کلیدها فقط در `allowed_keys` هستند و **نه** در `public_keys`: این‌ها
 * پارامترهای داخلی‌اند و هیچ‌کدام نباید بدون auth بیرون بروند.
 *
 * پوششِ تست: tests/Feature/Sales/SalesSettingsTest.php
 */
final class SalesSettings
{
    /** سقف یک تراکنش درگاه (ریال). */
    public function shaparakValue(): float
    {
        return max(0.0, $this->float('shaparak_value'));
    }

    /**
     * مبلغ بیعانه (ریال)، هرگز بزرگ‌تر از سقف درگاه.
     *
     * clamp در لحظهٔ **خواندن** انجام می‌شود و نه فقط در اعتبارسنجیِ فرم: اگر
     * ادمین بعداً `shaparak_value` را کم کند، مقدارِ بزرگِ قبلیِ `reserve_value`
     * در جدول باقی می‌ماند و بدون این clamp بی‌صدا اعمال می‌شد — یعنی بیعانه‌ای
     * که از سقفِ یک تراکنشِ درگاه بیشتر است و هیچ‌وقت قابل پرداخت نیست.
     */
    public function reserveValue(): float
    {
        return min(max(0.0, $this->float('reserve_value')), $this->shaparakValue());
    }

    /** مهلت نگهداری سبد. */
    public function parkTime(): CarbonInterval
    {
        return CarbonInterval::minutes(max(1, $this->int('park_time_minutes')));
    }

    /** ارفاق پس از انقضای رزرو، پیش از لغو. */
    public function reserveGrace(): CarbonInterval
    {
        return CarbonInterval::hours(max(0, $this->int('reserve_grace_hours')));
    }

    /** حداکثر اضافه‌بار مجاز نسبت به کل سفارش (درصد). */
    public function maxOverweightPercent(): float
    {
        return max(0.0, $this->float('max_overweight_percent'));
    }

    /**
     * آیا کوپن برای این کاربر نمایش/اعمال می‌شود؟
     *
     * مهمان (`null`) در حالت `selected` کوپن نمی‌بیند — معیار یک ستون روی
     * کاربر است و مهمان کاربری ندارد که رویش ست شده باشد.
     */
    public function couponVisibleFor(?User $user): bool
    {
        return match ($this->string('coupon_visibility')) {
            'none' => false,
            'selected' => $user !== null && (bool) $user->{User::COUPON_ENABLED},
            default => true,
        };
    }

    /** مقادیر مجاز `coupon_visibility` — فرمِ ادمین و تست از همین می‌خوانند. */
    public static function couponVisibilityOptions(): array
    {
        return ['all', 'none', 'selected'];
    }

    /**
     * قواعد اعتبارسنجیِ همین شش کلید، برای `PUT /admin/settings`.
     *
     * اینجا زندگی می‌کنند و نه در کنترلر، تا افزودنِ کلیدِ تازه یک نقطه داشته
     * باشد. قاعدهٔ متقاطعِ `reserve_value ≤ shaparak_value` اینجا نیست چون به
     * مقدارِ همزمانِ کلیدِ دیگر نیاز دارد؛ کنترلر آن را جدا چک می‌کند.
     *
     * @return array<string, array<int, string>>
     */
    public static function validationRules(): array
    {
        return [
            'shaparak_value' => ['numeric', 'min:0'],
            'reserve_value' => ['numeric', 'min:0'],
            'park_time_minutes' => ['integer', 'min:1'],
            'reserve_grace_hours' => ['integer', 'min:0'],
            'max_overweight_percent' => ['numeric', 'min:0', 'max:100'],
            'coupon_visibility' => ['in:'.implode(',', self::couponVisibilityOptions())],
        ];
    }

    /** مقدارِ خام: اول جدول تنظیمات، بعد config. */
    private function raw(string $key): mixed
    {
        $stored = Setting::allCached()[$key] ?? null;

        // رشتهٔ خالی یعنی «ادمین پاکش کرد»، نه «مقدارش صفر است» — به پیش‌فرض برگرد.
        if ($stored === null || $stored === '') {
            return config("sales.{$key}");
        }

        return $stored;
    }

    private function float(string $key): float
    {
        return (float) $this->raw($key);
    }

    private function int(string $key): int
    {
        return (int) $this->raw($key);
    }

    private function string(string $key): string
    {
        return (string) $this->raw($key);
    }
}
