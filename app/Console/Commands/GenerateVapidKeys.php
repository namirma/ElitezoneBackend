<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;

/**
 * ساختِ جفت‌کلیدِ VAPID برای Web Push.
 *
 * یک بار اجرا می‌شود و خروجی در `.env` می‌نشیند. کلیدها **رمزِ برنامه‌اند**:
 * با همان‌ها هر کسی می‌تواند به مشترکانِ شما نوتیف بفرستد، پس در گیت نروند.
 *
 * ⚠️ عوض‌کردنِ کلیدها همهٔ اشتراک‌های موجود را باطل می‌کند (مرورگر اشتراک را
 * به کلیدِ عمومی گره می‌زند). اگر ناچار شدید، جدولِ `device_tokens` را خالی
 * کنید تا کلاینت‌ها دوباره ثبت‌نام کنند.
 *
 * ## چرا کلید را خودمان می‌سازیم و نه با `VAPID::createVapidKeys()`
 *
 * آن متد `openssl_pkey_new()` را **بدونِ** پارامترِ `config` صدا می‌زند. روی
 * ویندوز/XAMPP — یعنی محیطِ توسعهٔ همین پروژه — اگر متغیرِ `OPENSSL_CONF`
 * تنظیم نباشد، همان تماس با پیامِ بی‌ربطِ «Unable to create the key» شکست
 * می‌خورد، و `putenv()` هم دردی دوا نمی‌کند (آزموده شد: OpenSSL مقدارِ
 * تنظیم‌شده در زمانِ اجرا را نمی‌بیند). دادنِ صریحِ مسیرِ `openssl.cnf` تنها
 * راهی است که کار می‌کند، و آن فقط وقتی ممکن است که خودمان کلید را بسازیم.
 *
 * خروجی دقیقاً همان قالبی است که `VAPID::validate()` می‌پذیرد: کلیدِ عمومی
 * ۶۵ بایتِ فشرده‌نشده (`0x04 || X || Y`) و کلیدِ خصوصی ۳۲ بایت، هر دو
 * base64url. `Notification/VapidKeyGenerationTest` همین را پین می‌کند.
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'push:vapid-keys';

    protected $description = 'ساختِ جفت‌کلیدِ VAPID برای نوتیفیکیشنِ پوش (یک بار، خروجی را در .env بگذارید)';

    /** طولِ هر مؤلفهٔ منحنیِ P-256 بر حسب بایت. */
    private const COORDINATE_LENGTH = 32;

    public function handle(): int
    {
        try {
            $keys = self::createKeys();
        } catch (RuntimeException $e) {
            $this->error('ساختِ کلید ناموفق بود: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('این سه خط را در فایل .env بگذارید:');
        $this->newLine();
        $this->line('PUSH_VAPID_SUBJECT=mailto:support@elitezone.me');
        $this->line('PUSH_VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('PUSH_VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->warn('کلیدِ خصوصی را در گیت نگذارید. عوض‌کردنشان همهٔ اشتراک‌های فعلی را باطل می‌کند.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @return array{publicKey: string, privateKey: string}
     */
    public static function createKeys(): array
    {
        $args = [
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ];

        if ($config = self::opensslConfigPath()) {
            $args['config'] = $config;
        }

        $key = openssl_pkey_new($args);

        if ($key === false) {
            throw new RuntimeException(
                'OpenSSL نتوانست کلید بسازد. اگر روی ویندوز هستید، معمولاً یعنی openssl.cnf پیدا نشد.'
            );
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || ! isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
            throw new RuntimeException('کلیدِ ساخته‌شده مؤلفه‌های منحنی را ندارد.');
        }

        // مؤلفه‌ها ممکن است با بایتِ صفرِ ابتدایی کوتاه‌تر برگردند؛ بدونِ pad
        // طولِ کلید گاهی ۶۴ بایت می‌شود و VAPID::validate ردش می‌کند —
        // یعنی خطایی که فقط در بعضی از تولیدها ظاهر می‌شود.
        $x = self::pad($details['ec']['x']);
        $y = self::pad($details['ec']['y']);
        $d = self::pad($details['ec']['d']);

        return [
            'publicKey' => self::base64url("\x04".$x.$y),
            'privateKey' => self::base64url($d),
        ];
    }

    private static function pad(string $component): string
    {
        return str_pad($component, self::COORDINATE_LENGTH, "\0", STR_PAD_LEFT);
    }

    private static function base64url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    /**
     * مسیرِ `openssl.cnf` روی نصب‌های ویندوزی/XAMPP. روی لینوکسِ پروداکشن
     * `null` برمی‌گردد و `openssl_pkey_new` پیش‌فرضِ سیستم را می‌خواند.
     */
    private static function opensslConfigPath(): ?string
    {
        foreach (['C:\xampp\php\extras\ssl\openssl.cnf', 'C:\xampp\apache\conf\openssl.cnf'] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
