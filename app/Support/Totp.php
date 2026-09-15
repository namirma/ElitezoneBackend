<?php

namespace App\Support;

/**
 * TOTP (RFC 6238) روی HMAC-SHA1، گام ۳۰ ثانیه، ۶ رقم — همان چیزی که Google
 * Authenticator / Aegis / 1Password می‌سازند.
 *
 * بدونِ وابستگیِ Composer: کلِ الگوریتم HMAC + یک truncation است (RFC 4226 §5.3)
 * و PHP همه‌اش را دارد؛ فقط base32 (RFC 4648) لازم بود که این‌جا نوشته شده.
 * بردارهای آزمونِ RFC 6238 پیوست B در `Unit/Support/TotpTest` پین شده‌اند.
 *
 * پنجره‌ی ±۱ گام یعنی کدِ ۳۰ ثانیه‌ی قبل/بعد هم پذیرفته می‌شود (انحرافِ ساعتِ
 * گوشی). `verify()` شماره‌ی گامِ منطبق را برمی‌گرداند تا فراخوان بتواند
 * استفاده‌ی دوباره‌ی همان کد را رد کند (RFC 6238 §5.2).
 */
final class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** رازِ ۲۰ بایتیِ تصادفی (۱۶۰ بیت، همان‌قدر که RFC 4226 توصیه می‌کند) به base32. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function at(string $secretBase32, int $timestamp, int $digits = self::DIGITS, int $period = self::PERIOD): string
    {
        return self::hotp(self::base32Decode($secretBase32), intdiv($timestamp, $period), $digits);
    }

    /**
     * گامِ منطبق، یا null اگر کد در پنجره نبود.
     */
    public static function verify(string $secretBase32, string $code, ?int $timestamp = null, int $window = 1): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $key = self::base32Decode($secretBase32);
        $step = intdiv($timestamp ?? time(), self::PERIOD);

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::hotp($key, $step + $offset, self::DIGITS), $code)) {
                return $step + $offset;
            }
        }

        return null;
    }

    /** آدرسی که اپ‌های Authenticator می‌خوانند (به‌صورت QR یا لینک). */
    public static function otpauthUri(string $secretBase32, string $account, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer.':'.$account),
            $secretBase32,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }

    private static function hotp(string $key, int $counter, int $digits): string
    {
        $hash = hash_hmac('sha1', pack('J', $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $binary): string
    {
        $bits = '';
        foreach (str_split($binary) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $base32): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($base32, '='))) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                continue;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
