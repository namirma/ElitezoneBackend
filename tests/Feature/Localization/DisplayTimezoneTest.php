<?php

namespace Tests\Feature\Localization;

use App\Http\Resources\Shared\UserMessageResource;
use App\Models\Order;
use App\Models\UserNotification;
use App\Support\JalaliDate;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * استیجینگ، ۲۰۲۶-۰۹-۱۴: اعلانی که ساعتِ ۱۸:۰۵ تهران ساخته شده بود «۱۴:۳۵» نمایش داده
 * می‌شد — ساعتِ UTC. ذخیره و مقایسه UTC می‌ماند؛ فقط رشته‌ی نمایشی به تهران می‌رود.
 */
class DisplayTimezoneTest extends TestCase
{
    #[Test]
    public function a_utc_instant_is_displayed_in_tehran_time(): void
    {
        $utc = Carbon::parse('2026-09-14 14:35:00', 'UTC');

        $this->assertSame('1405/06/23 18:05', JalaliDate::format($utc));
        $this->assertSame('2026-09-14 14:35:00 UTC', $utc->format('Y-m-d H:i:s T'), 'the caller\'s instance is not mutated');

        // ۲۱:۰۰ UTC در تهران روزِ بعد است — فیلدهای فقط‌تاریخ هم باید جلو بروند.
        $this->assertSame('1405/06/24', JalaliDate::format('2026-09-14 21:00:00', 'Y/m/d'));

        // مسیرِ واقعیِ گزارش: /profile/messages
        $message = (new UserMessageResource((new UserNotification)->forceFill(['created_at' => $utc])))
            ->toArray(new Request);
        $this->assertSame('1405/06/23 18:05', $message['created_at_p']);
        $this->assertSame('2026-09-14 14:35:00', $message['created_at'], 'the raw field keeps its UTC contract');

        // trait `PersianDate` — سیزده مدل از آن می‌خوانند.
        $this->assertSame('1405-06-23 18:05:00', (new Order)->forceFill(['created_at' => $utc])->created_at_p);
    }
}
