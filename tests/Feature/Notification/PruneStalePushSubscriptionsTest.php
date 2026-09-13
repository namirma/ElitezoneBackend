<?php

namespace Tests\Feature\Notification;

use App\Models\DeviceToken;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `push:prune-stale` — حذفِ اشتراک‌های رهاشده.
 *
 * چهار حالت، چون دو شاخه دارد و هر کدام دو طرف: `last_seen_at` قدیمی/تازه،
 * و نبودنِ `last_seen_at` با `updated_at` قدیمی/تازه. حالتِ آخر همان است که
 * یک `orWhere` بدونِ گروه‌بندی خرابش می‌کرد و هر ردیفِ بی‌`last_seen_at`ی را
 * صرف‌نظر از تاریخ حذف می‌کرد.
 */
class PruneStalePushSubscriptionsTest extends TestCase
{
    use DatabaseTransactions;

    private function subscription(string $endpoint, ?CarbonInterface $lastSeen, CarbonInterface $updatedAt): void
    {
        $user = User::factory()->create();

        DeviceToken::create([
            DeviceToken::USER_ID => $user->id,
            DeviceToken::ENDPOINT => $endpoint,
            DeviceToken::PUBLIC_KEY => 'key',
            DeviceToken::AUTH_KEY => 'auth',
            DeviceToken::PLATFORM => DeviceToken::PLATFORM_WEB,
        ]);

        // مستقیم روی جدول: مدل `updated_at` را هنگامِ ذخیره خودش بازنویسی می‌کند.
        DB::table('device_tokens')->where(DeviceToken::ENDPOINT, $endpoint)->update([
            DeviceToken::LAST_SEEN_AT => $lastSeen,
            DeviceToken::UPDATED_AT => $updatedAt,
        ]);
    }

    #[Test]
    public function it_removes_only_subscriptions_not_seen_within_the_window(): void
    {
        $this->subscription('https://push.test/stale', now()->subDays(100), now()->subDays(100));
        $this->subscription('https://push.test/fresh', now()->subDays(10), now()->subDays(100));
        $this->subscription('https://push.test/never-seen-old', null, now()->subDays(100));
        $this->subscription('https://push.test/never-seen-recent', null, now()->subDays(5));

        $this->artisan('push:prune-stale')->assertExitCode(0);

        $remaining = DeviceToken::pluck(DeviceToken::ENDPOINT)->all();

        $this->assertNotContains('https://push.test/stale', $remaining);
        $this->assertNotContains('https://push.test/never-seen-old', $remaining);

        // `updated_at` قدیمی است ولی دستگاه اخیراً دیده شده — `last_seen_at` برنده است.
        $this->assertContains('https://push.test/fresh', $remaining);
        // بدونِ `last_seen_at` ولی تازه — نباید فقط به‌خاطرِ نبودنِ ستون حذف شود.
        $this->assertContains('https://push.test/never-seen-recent', $remaining);
    }

    #[Test]
    public function a_zero_day_window_is_refused_rather_than_wiping_the_table(): void
    {
        $this->subscription('https://push.test/today', now(), now());

        // ‏`--days=0` یعنی «هر چیزی قدیمی‌تر از همین لحظه» — یعنی کلِ جدول.
        $this->artisan('push:prune-stale', ['--days' => 0])->assertExitCode(1);

        $this->assertSame(1, DeviceToken::count());
    }
}
