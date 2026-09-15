<?php

namespace Tests\Unit\Support;

use App\Support\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * بردارهای آزمونِ RFC 6238 پیوست B (SHA1، راز `12345678901234567890`) — ۸ رقم،
 * همان‌طور که RFC چاپشان کرده؛ ۶ رقمِ ما همان مقدار بدونِ دو رقمِ اول است.
 */
class TotpTest extends TestCase
{
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // base32("12345678901234567890")

    /** @return list<array{int, string}> */
    public static function rfc6238Vectors(): array
    {
        return [
            [59, '94287082'],
            [1111111109, '07081804'],
            [1111111111, '14050471'],
            [1234567890, '89005924'],
            [2000000000, '69279037'],
            [20000000000, '65353130'],
        ];
    }

    #[Test]
    #[DataProvider('rfc6238Vectors')]
    public function it_matches_the_rfc_6238_vectors(int $timestamp, string $expected): void
    {
        $this->assertSame($expected, Totp::at(self::RFC_SECRET, $timestamp, 8));
        $this->assertSame(substr($expected, 2), Totp::at(self::RFC_SECRET, $timestamp));
    }

    #[Test]
    public function base32_round_trips_the_rfc_secret(): void
    {
        $this->assertSame(self::RFC_SECRET, Totp::base32Encode('12345678901234567890'));
        $this->assertSame('12345678901234567890', Totp::base32Decode(self::RFC_SECRET));
        $this->assertSame('12345678901234567890', Totp::base32Decode(strtolower(self::RFC_SECRET).'===='));
    }

    #[Test]
    public function verify_accepts_one_step_either_side_and_nothing_further(): void
    {
        $now = 1111111111; // step 37037037
        $step = intdiv($now, Totp::PERIOD);

        $this->assertSame($step, Totp::verify(self::RFC_SECRET, Totp::at(self::RFC_SECRET, $now), $now));
        $this->assertSame($step - 1, Totp::verify(self::RFC_SECRET, Totp::at(self::RFC_SECRET, $now - 30), $now));
        $this->assertSame($step + 1, Totp::verify(self::RFC_SECRET, Totp::at(self::RFC_SECRET, $now + 30), $now));
        $this->assertNull(Totp::verify(self::RFC_SECRET, Totp::at(self::RFC_SECRET, $now - 60), $now));
        $this->assertNull(Totp::verify(self::RFC_SECRET, Totp::at(self::RFC_SECRET, $now + 60), $now));
    }

    #[Test]
    public function verify_rejects_malformed_codes_without_throwing(): void
    {
        $this->assertNull(Totp::verify(self::RFC_SECRET, '', 1111111111));
        $this->assertNull(Totp::verify(self::RFC_SECRET, '12345', 1111111111));
        $this->assertNull(Totp::verify(self::RFC_SECRET, 'abcdef', 1111111111));
        // ارقامِ فارسی رقم نیستند؛ کلاینت باید لاتین بفرستد.
        $this->assertNull(Totp::verify(self::RFC_SECRET, '۰۵۰۴۷۱', 1111111111));
        // فاصله/خط‌تیره‌ی تزئینی حذف می‌شود.
        $this->assertNotNull(Totp::verify(self::RFC_SECRET, '050 471', 1111111111));
    }

    #[Test]
    public function generated_secrets_are_160_bit_base32(): void
    {
        $secret = Totp::generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertSame(20, strlen(Totp::base32Decode($secret)));
        $this->assertNotSame($secret, Totp::generateSecret());
    }

    #[Test]
    public function the_otpauth_uri_carries_label_issuer_and_parameters(): void
    {
        $uri = Totp::otpauthUri(self::RFC_SECRET, '09120000000', 'EliteZone');

        $this->assertSame(
            'otpauth://totp/EliteZone%3A09120000000?secret='.self::RFC_SECRET.'&issuer=EliteZone&algorithm=SHA1&digits=6&period=30',
            $uri
        );
    }
}
