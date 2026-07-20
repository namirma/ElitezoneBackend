<?php

namespace App\Support;

use Illuminate\Support\Facades\Hash;

/**
 * ذخیره و مقایسه امن کدهای یکبارمصرف/توکن بازنشانی (users.secret).
 *
 * مقدار خام هرگز در دیتابیس ذخیره نمی‌شود — bcrypt (Hash) نگه داشته می‌شود تا
 * افشای دیتابیس/بکاپ، کد فعال کاربران را لو ندهد. Hash::check ذاتاً timing-safe
 * است؛ الگوی قدیمی hash_equals روی مقدار خام را جایگزین می‌کند.
 *
 * نکته دیپلوی: secretهای خامِ از قبل ذخیره‌شده دیگر match نمی‌شوند — کاربرِ
 * وسطِ فلو باید کد جدید بگیرد (پنجره‌ها ۲ تا ۱۵ دقیقه‌اند؛ بی‌اثر عملی).
 */
final class OtpSecret
{
    public static function hash(string $plain): string
    {
        return Hash::make($plain);
    }

    public static function matches(?string $stored, string $plain): bool
    {
        if ($stored === null || $stored === '' || $plain === '') {
            return false;
        }

        return Hash::check($plain, $stored);
    }
}
