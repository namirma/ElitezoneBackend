<?php

namespace Tests\Feature\Notification;

use App\Jobs\Notification\SendPushJob;
use App\Models\DeviceToken;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\PushService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * اسلایسِ پوش: ثبتِ اشتراک، مالکیت، قلابِ مدل، و گاردِ محیطِ غیرپروداکشن.
 *
 * دو موردِ آخر آن‌هایی‌اند که بی‌صدا خراب می‌شوند: اگر قلابِ
 * `UserNotification::created` برداشته شود هیچ تستِ دیگری قرمز نمی‌شود و اپ
 * فقط «نوتیف نمی‌آید»؛ و اگر گاردِ محیط برداشته شود، اولین `queue:work` روی
 * لپ‌تاپ به دستگاهِ مشتریِ واقعی نوتیف می‌فرستد — همان اتفاقی که یک‌بار با
 * پیامک افتاد.
 *
 * ⚠️ اینجا هیچ ارسالِ واقعیِ Web Push انجام نمی‌شود: کتابخانه با کلاینتِ PSR-18
 * خودش مستقیم به endpoint می‌زند و `Http::fake` لاراول آن را نمی‌گیرد. پس
 * تست‌ها روی مرزهایی می‌ایستند که خودمان نوشته‌ایم (گارد، قلاب، مالکیت) و
 * حالتِ «کلید تنظیم نشده» — نه روی رمزگذاریِ خودِ کتابخانه.
 */
class PushDeviceTokenTest extends TestCase
{
    use DatabaseTransactions;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc-123';

    private const P256DH = 'BM1hQ2hLZTFzdGVzdHB1YmxpY2tleXZhbHVlZm9ydGVzdGluZ29ubHk';

    private const AUTH = 'c2VjcmV0YXV0aHZhbHVl';

    /** @return array<string, string> */
    private function payload(string $endpoint = self::ENDPOINT): array
    {
        return [
            'endpoint' => $endpoint,
            'public_key' => self::P256DH,
            'auth_key' => self::AUTH,
            'platform' => DeviceToken::PLATFORM_WEB,
        ];
    }

    #[Test]
    public function it_registers_a_subscription_idempotently(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/profile/devices', $this->payload())->assertOk();
        $this->postJson('/api/profile/devices', $this->payload())->assertOk();

        // اپ در هر اجرا ثبت می‌کند؛ دو ردیف یعنی هر کاربر بعد از یک هفته
        // ده‌ها نسخهٔ تکراری از یک دستگاه دارد و هر نوتیف چند بار می‌رسد.
        $this->assertSame(1, DeviceToken::where(DeviceToken::ENDPOINT, self::ENDPOINT)->count());
    }

    #[Test]
    public function it_rejects_a_subscription_without_encryption_keys(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());

        // بدونِ p256dh/auth نمی‌شود payload را رمز کرد؛ ردیفِ نصفه فقط یک
        // اشتراکِ همیشه-ناموفق می‌سازد که هیچ‌وقت پاک نمی‌شود.
        $this->postJson('/api/profile/devices', [
            'endpoint' => self::ENDPOINT,
            'platform' => DeviceToken::PLATFORM_WEB,
        ])->assertStatus(422);
    }

    #[Test]
    public function re_registering_an_existing_subscription_moves_its_owner(): void
    {
        Queue::fake();
        $first = User::factory()->create();
        $second = User::factory()->create();

        Sanctum::actingAs($first);
        $this->postJson('/api/profile/devices', $this->payload())->assertOk();

        Sanctum::actingAs($second);
        $this->postJson('/api/profile/devices', $this->payload())->assertOk();

        // دستگاه دستِ کاربرِ دوم است. اگر مالک عوض نشود، نوتیفیکیشن‌های کاربرِ
        // اول روی دستگاهِ او ظاهر می‌شود — نشتِ اطلاعات، نه صرفاً آزار.
        $this->assertSame(
            $second->id,
            (int) DeviceToken::where(DeviceToken::ENDPOINT, self::ENDPOINT)->value(DeviceToken::USER_ID),
        );
    }

    #[Test]
    public function a_user_cannot_delete_someone_elses_subscription(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();

        DeviceToken::create($this->payload() + [DeviceToken::USER_ID => $owner->id]);

        Sanctum::actingAs($other);
        $this->deleteJson('/api/profile/devices', ['endpoint' => self::ENDPOINT])->assertOk();

        $this->assertDatabaseHas('device_tokens', [DeviceToken::ENDPOINT => self::ENDPOINT]);
    }

    #[Test]
    public function it_keeps_only_the_newest_subscriptions_per_user(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // ۱۲ ثبت، سقف ۱۰. بدونِ هرس، یک کلاینتِ بدخواه جدول را پر می‌کند و
        // هر نوتیفیکیشنِ بعدی همان تعداد درخواستِ محکوم‌به‌شکست می‌سازد.
        foreach (range(1, 12) as $i) {
            $this->postJson('/api/profile/devices', $this->payload(self::ENDPOINT.'-'.$i))->assertOk();
        }

        $remaining = DeviceToken::where(DeviceToken::USER_ID, $user->id)->pluck(DeviceToken::ENDPOINT);

        $this->assertCount(10, $remaining);
        // تازه‌ترین باید مانده باشد و قدیمی‌ترین رفته.
        $this->assertContains(self::ENDPOINT.'-12', $remaining->all());
        $this->assertNotContains(self::ENDPOINT.'-1', $remaining->all());
    }

    #[Test]
    public function the_register_endpoint_is_rate_limited(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());

        $status = 200;

        // سقف ۲۰ در دقیقه؛ درخواستِ ۲۱ام باید ۴۲۹ بگیرد.
        foreach (range(1, 21) as $i) {
            $status = $this->postJson('/api/profile/devices', $this->payload(self::ENDPOINT.'-'.$i))->getStatusCode();
        }

        $this->assertSame(429, $status);
    }

    #[Test]
    public function creating_an_in_app_notification_queues_a_push(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        UserNotification::create([
            UserNotification::USER_ID => $user->id,
            UserNotification::SUBJECT => 'موضوع',
            UserNotification::BODY => 'متن',
        ]);

        Queue::assertPushed(SendPushJob::class);
    }

    #[Test]
    public function the_whole_chain_runs_without_faking_the_queue(): void
    {
        // بقیهٔ تست‌ها صف را fake می‌کنند، پس بدنهٔ جاب هرگز اجرا نمی‌شود و یک
        // خطای تایپی در `SendPushJob::handle` (مثلاً امضای عوض‌شدهٔ
        // PushService) از همه‌شان سبز رد می‌شد. اینجا `QUEUE_CONNECTION=sync`
        // تستی کار خودش را می‌کند: قلاب → جاب → سرویس → گارد، واقعی.
        config([
            'services.push.vapid.public_key' => 'test-public',
            'services.push.vapid.private_key' => 'test-private',
            'services.push.dev_allowlist' => [],
        ]);

        $user = User::factory()->create();
        DeviceToken::create($this->payload() + [DeviceToken::USER_ID => $user->id]);

        UserNotification::create([
            UserNotification::USER_ID => $user->id,
            UserNotification::SUBJECT => 'موضوع',
            UserNotification::BODY => 'متن',
        ]);

        // گارد جلوی هر ترافیک شبکه‌ای را می‌گیرد؛ چیزی که سنجیده می‌شود این
        // است که زنجیره بدون exception تا انتها می‌رود و ردیف باقی می‌ماند
        // (اشتراک منقضی نشده، پس نباید حذف شود).
        $this->assertDatabaseHas('device_tokens', [DeviceToken::USER_ID => $user->id]);
    }

    #[Test]
    public function non_production_sends_nothing_to_a_user_outside_the_allowlist(): void
    {
        config([
            'services.push.vapid.subject' => 'mailto:dev@elitezone.me',
            'services.push.vapid.public_key' => 'test-public',
            'services.push.vapid.private_key' => 'test-private',
            'services.push.dev_allowlist' => [],
        ]);

        $user = User::factory()->create();
        DeviceToken::create($this->payload() + [DeviceToken::USER_ID => $user->id]);

        // true یعنی «از دیدِ صف ارسال شد» — جاب نباید retry/fail کند. اگر گارد
        // برداشته شود، کلیدهای قلابیِ بالا باعثِ خطای واقعی و false می‌شوند،
        // پس این assert دقیقاً همان گارد را می‌سنجد.
        $this->assertTrue(app(PushService::class)->sendToUser($user->id, 'موضوع', 'متن'));
    }

    #[Test]
    public function an_unconfigured_vapid_keypair_is_a_no_op_not_a_failure(): void
    {
        config([
            'services.push.vapid.public_key' => '',
            'services.push.vapid.private_key' => '',
            'services.push.dev_allowlist' => [],
        ]);

        $user = User::factory()->create();
        DeviceToken::create($this->payload() + [DeviceToken::USER_ID => $user->id]);

        $this->assertTrue(app(PushService::class)->sendToUser($user->id, 'موضوع', 'متن'));
    }

    #[Test]
    public function the_vapid_public_key_is_exposed_to_the_frontend(): void
    {
        config(['services.push.vapid.public_key' => 'the-public-key']);

        // فرانت بدونِ این مقدار اصلاً نمی‌تواند اشتراک بسازد.
        $this->getJson('/api/settings/public')
            ->assertOk()
            ->assertJsonPath('data.vapid_public_key', 'the-public-key');
    }
}
