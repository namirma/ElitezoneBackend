<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\Storage;

/**
 * هویتی که مشتری از پشتیبان می‌بیند — نام، حوزه‌ی کاری و تصویر.
 *
 * تا پیش از این هر پاسخِ پشتیبانی با نامِ عمومیِ «پشتیبانی الیت‌زون» می‌آمد.
 * تصمیمِ محصول این شد که مشتری بداند دقیقاً با چه کسی و از کدام بخش صحبت می‌کند
 * («سجاد زرسنج (پشتیبان بخش مالی)») — هم پاسخگویی را شخصی می‌کند و هم پیگیریِ
 * بعدی را برای خودِ مشتری ساده‌تر.
 *
 * دو قیدِ عمدی:
 *
 *  ۱. تصویر **فقط** از ستونِ تأییدشده خوانده می‌شود. تصویرِ در انتظارِ تأیید
 *     هرگز از این کلاس بیرون نمی‌رود؛ همین‌جا تنها جایی است که تصویرِ ادمین به
 *     مشتری می‌رسد، پس صافیِ نهایی باید اینجا باشد نه در فراخوان.
 *
 *  ۲. اگر ادمین نامِ واقعی ثبت نکرده باشد، به همان برچسبِ عمومی برمی‌گردیم.
 *     نمایشِ شماره‌ی موبایلِ کارمند به مشتری، نشتِ اطلاعات است نه fallback.
 */
class SupportIdentity
{
    /**
     * نگاشتِ مجوز → حوزه‌ی پشتیبانی، به ترتیبِ اولویتِ نمایش.
     *
     * ادمینی که چند مجوز دارد با تخصصی‌ترینشان معرفی می‌شود؛ «پشتیبان بخش مالی»
     * برای مشتری معنادارتر از فهرستِ چهار حوزه است.
     */
    private const AREA_BY_PERMISSION = [
        'manage-financial' => 'پشتیبان بخش مالی',
        'manage-orders' => 'پشتیبان بخش سفارش‌ها',
        'manage-catalog' => 'پشتیبان بخش محصولات',
        'manage-users' => 'پشتیبان بخش کاربران',
        'manage-tickets' => 'کارشناس پشتیبانی',
        'manage-content' => 'پشتیبان بخش محتوا',
    ];

    /**
     * @return array{name: string, area: string|null, display_name: string, avatar_url: string|null}
     */
    public static function forAdmin(?User $admin): array
    {
        $fallback = __('ticket.support_author');

        if (! $admin instanceof User) {
            return [
                'name' => $fallback,
                'area' => null,
                'display_name' => $fallback,
                'avatar_url' => null,
            ];
        }

        $name = self::fullName($admin) ?? $fallback;
        $area = self::area($admin);

        return [
            'name' => $name,
            'area' => $area,
            // یک رشته‌ی آماده هم برمی‌گردد تا هر مصرف‌کننده‌ای قالبِ پرانتز را
            // از نو نسازد و دو کلاینت دو شکلِ متفاوت نشان ندهند.
            'display_name' => $area ? "{$name} ({$area})" : $name,
            'avatar_url' => self::approvedAvatarUrl($admin),
        ];
    }

    private static function fullName(User $admin): ?string
    {
        $profile = $admin->relationLoaded('profile') ? $admin->profile : $admin->profile()->first();

        if (! $profile instanceof UserProfile) {
            return null;
        }

        $full = trim(($profile->{UserProfile::NAME} ?? '').' '.($profile->{UserProfile::FAMILY} ?? ''));

        return $full !== '' ? $full : null;
    }

    private static function area(User $admin): ?string
    {
        foreach (self::AREA_BY_PERMISSION as $permission => $label) {
            if ($admin->hasPermission($permission)) {
                return $label;
            }
        }

        return null;
    }

    /**
     * فقط تصویرِ تأییدشده. ستونِ pending عمداً خوانده نمی‌شود.
     */
    private static function approvedAvatarUrl(User $admin): ?string
    {
        $profile = $admin->relationLoaded('profile') ? $admin->profile : $admin->profile()->first();

        if (! $profile instanceof UserProfile) {
            return null;
        }

        $avatar = $profile->{UserProfile::AVATAR};
        if (! $avatar) {
            return null;
        }

        return str_starts_with($avatar, 'http') ? $avatar : Storage::disk('public')->url($avatar);
    }
}
