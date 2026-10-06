<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Events\User\CustomerSegmentChanged;
use App\Jobs\Notification\SendSmsJob;
use App\Listeners\User\SendWinBackOffer;
use App\Models\Coupon;
use App\Models\CustomerStat;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * مدیریت، دورِ پنجم (۱۴۰۵/۰۷/۱۴): «تخفیفِ ناشی از بازگشتِ مشتری را متوقف و حذف کنید.» کد می‌ماند
 * (قانونِ یک) پشتِ `features.win_back_coupons`، پیش‌فرض خاموش: نه کوپنی ساخته می‌شود نه پیامکی.
 */
class WinBackOfferIsOffTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function a_declining_customer_gets_no_coupon_and_no_sms_by_default(): void
    {
        $this->assertFalse((bool) config('features.win_back_coupons'));
        Bus::fake([SendSmsJob::class]);
        $user = User::factory()->create();
        $couponsBefore = Coupon::count();

        app(SendWinBackOffer::class)->handle(
            new CustomerSegmentChanged($user, CustomerStat::SEGMENT_LOYAL, CustomerStat::SEGMENT_AT_RISK)
        );

        $this->assertSame($couponsBefore, Coupon::count());
        Bus::assertNotDispatched(SendSmsJob::class);
    }
}
