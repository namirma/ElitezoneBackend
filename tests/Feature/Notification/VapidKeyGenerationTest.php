<?php

namespace Tests\Feature\Notification;

use App\Console\Commands\GenerateVapidKeys;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * کلیدی که خودمان می‌سازیم باید دقیقاً همان چیزی باشد که کتابخانه می‌پذیرد.
 *
 * `VAPID::validate()` داورِ این تست است، نه یک قالبِ حدسی: اگر فرمتِ ما یک بایت
 * جابه‌جا باشد (مثلاً بایتِ `0x04` جا بیفتد یا مؤلفه‌ها pad نشوند) همان‌جا رد
 * می‌شود. بدونِ این تست، خطا فقط موقعِ اولین ارسالِ واقعی ظاهر می‌شد — یعنی
 * روی پروداکشن.
 */
class VapidKeyGenerationTest extends TestCase
{
    #[Test]
    public function generated_keys_are_accepted_by_the_library(): void
    {
        $keys = GenerateVapidKeys::createKeys();

        $validated = VAPID::validate([
            'subject' => 'mailto:support@elitezone.me',
            'publicKey' => $keys['publicKey'],
            'privateKey' => $keys['privateKey'],
        ]);

        // validate() مقدارِ decode شده را برمی‌گرداند: ۶۵ بایت و ۳۲ بایت.
        $this->assertSame(65, strlen($validated['publicKey']));
        $this->assertSame(32, strlen($validated['privateKey']));

        // بایتِ اولِ نقطهٔ فشرده‌نشدهٔ منحنی همیشه 0x04 است.
        $this->assertSame("\x04", $validated['publicKey'][0]);
    }

    #[Test]
    public function each_run_produces_a_different_keypair(): void
    {
        // اگر روزی به یک مقدارِ ثابت تبدیل شود، همهٔ نصب‌ها یک کلیدِ خصوصی
        // مشترک می‌گیرند و هر کسی می‌تواند به مشترکانِ بقیه نوتیف بفرستد.
        $this->assertNotSame(
            GenerateVapidKeys::createKeys()['privateKey'],
            GenerateVapidKeys::createKeys()['privateKey'],
        );
    }
}
