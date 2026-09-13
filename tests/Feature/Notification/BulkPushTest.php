<?php

namespace Tests\Feature\Notification;

use App\Jobs\Notification\SendBulkNotificationJob;
use App\Jobs\Notification\SendBulkPushJob;
use App\Jobs\Notification\SendPushJob;
use App\Models\DeviceToken;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\PushService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * پوشِ ارسالِ گروهیِ ادمین.
 *
 * هر سه تستِ اول درباره‌ی یک خاصیت‌اند که بی‌صدا خراب می‌شود: **retryِ جابِ
 * گروهی نباید به هیچ‌کس دو بار پوش بدهد.** آن جاب با `batchId` ثابت و
 * `insertOrIgnore` تکرار را برای ردیف‌ها بی‌اثر می‌کند؛ اگر پوش برای کلِ تکه
 * dispatch می‌شد، ردیف رد می‌شد ولی گوشی دو بار زنگ می‌خورد — و هیچ تستِ دیگری
 * متوجه نمی‌شد، چون تعدادِ ردیف‌ها درست می‌ماند.
 */
class BulkPushTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function a_bulk_send_queues_one_push_job_per_chunk_not_per_user(): void
    {
        Queue::fake();
        $ids = User::factory()->count(3)->create()->pluck('id')->map(fn ($id) => (int) $id)->all();

        (new SendBulkNotificationJob('موضوع', 'متن', $ids, null))->handle();

        Queue::assertPushed(SendBulkPushJob::class, 1);
        Queue::assertPushed(SendBulkPushJob::class, fn (SendBulkPushJob $job) => $job->userIds === $ids);

        // `insertOrIgnore` قلابِ مدل را دور می‌زند؛ اگر روزی به `create()` تبدیل
        // شود، هر گیرنده یک پوشِ تکی **به‌علاوه‌ی** پوشِ گروهی می‌گیرد.
        Queue::assertNotPushed(SendPushJob::class);
    }

    #[Test]
    public function a_retry_of_the_same_batch_pushes_nobody_twice(): void
    {
        Queue::fake();
        $ids = User::factory()->count(3)->create()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $job = new SendBulkNotificationJob('موضوع', 'متن', $ids, null);

        $job->handle();
        $job->handle(); // retryِ صف، با همان batchId

        Queue::assertPushed(SendBulkPushJob::class, 1);
        $this->assertSame(3, UserNotification::where(UserNotification::BATCH_ID, $job->batchId)->count());
    }

    #[Test]
    public function a_retry_after_a_partial_run_pushes_only_the_remainder(): void
    {
        Queue::fake();
        $ids = User::factory()->count(3)->create()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $job = new SendBulkNotificationJob('موضوع', 'متن', $ids, null);

        // اجرای اول برای نفرِ اول ردیف درج کرد و بعد مُرد.
        UserNotification::insert([
            UserNotification::USER_ID => $ids[0],
            UserNotification::SENDER_ID => null,
            UserNotification::BATCH_ID => $job->batchId,
            UserNotification::SUBJECT => 'موضوع',
            UserNotification::BODY => 'متن',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $job->handle();

        // نفرِ اول در اجرای قبلی پوش گرفته بود؛ فقط دو نفرِ باقی.
        Queue::assertPushed(
            SendBulkPushJob::class,
            fn (SendBulkPushJob $push) => $push->userIds === [$ids[1], $ids[2]],
        );
        $this->assertSame(3, UserNotification::where(UserNotification::BATCH_ID, $job->batchId)->count());
    }

    #[Test]
    public function outside_production_a_bulk_push_is_filtered_to_the_allowlist_not_all_or_nothing(): void
    {
        $allowed = User::factory()->create();
        $outsider = User::factory()->create();

        // کلیدهای VAPIDِ عمداً نامعتبر: هر تلاشِ واقعی برای ارسال، داخلِ `deliver()`
        // خطا می‌دهد و `false` برمی‌گرداند. پس `true` یعنی واقعاً چیزی ارسال نشد.
        config([
            'services.push.vapid.subject' => 'mailto:dev@elitezone.me',
            'services.push.vapid.public_key' => 'not-a-real-key',
            'services.push.vapid.private_key' => 'not-a-real-key',
            'services.push.dev_allowlist' => [$allowed->id],
        ]);

        // فقط فردِ خارج از allowlist اشتراک دارد.
        DeviceToken::create([
            DeviceToken::USER_ID => $outsider->id,
            DeviceToken::ENDPOINT => 'https://fcm.googleapis.com/fcm/send/outsider',
            DeviceToken::PUBLIC_KEY => 'BM1hQ2hLZTFzdGVzdHB1YmxpY2tleXZhbHVlZm9ydGVzdGluZ29ubHk',
            DeviceToken::AUTH_KEY => 'c2VjcmV0YXV0aHZhbHVl',
            DeviceToken::PLATFORM => DeviceToken::PLATFORM_WEB,
        ]);

        // فیلتر کار می‌کند ⇒ فقط `allowed` می‌ماند، که اشتراکی ندارد ⇒ هیچ ارسالی.
        $this->assertTrue(app(PushService::class)->sendToUsers([$allowed->id, $outsider->id], 'ت', 'م'));

        // کنترل: وقتی outsider هم مجاز باشد، همان فراخوانی واقعاً به ارسال می‌رسد و
        // با کلیدهای نامعتبر false می‌دهد. بدونِ این، `true` بالا می‌توانست از هر
        // دلیلِ دیگری بیاید و تست چیزی را نسنجیده بود.
        config(['services.push.dev_allowlist' => [$allowed->id, $outsider->id]]);
        $this->assertFalse(app(PushService::class)->sendToUsers([$allowed->id, $outsider->id], 'ت', 'م'));
    }
}
