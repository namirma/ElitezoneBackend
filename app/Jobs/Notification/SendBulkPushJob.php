<?php

namespace App\Jobs\Notification;

use App\Services\PushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * پوشِ یک تکه از ارسالِ گروهیِ ادمین.
 *
 * ⚠️ **یک جاب به‌ازای هر تکه، نه هر گیرنده.** ارسالِ همگانی به چند ده هزار
 * کاربر با یک جاب به‌ازای هر نفر، صف را برای ساعت‌ها با کارهایی پر می‌کرد که
 * اغلبشان هیچ اشتراکی ندارند — و هر پوشِ واقعیِ دیگری (پرداختِ سفارش،
 * لغو) پشتِ آن‌ها منتظر می‌ماند. `SendBulkNotificationJob` همین تکه‌ها را
 * می‌سازد و فقط برای گیرندگانی dispatch می‌کند که ردیفشان در همان اجرا تازه
 * درج شده.
 */
class SendBulkPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120];

    /**
     * @param  list<int>  $userIds
     */
    public function __construct(
        public readonly array $userIds,
        public readonly string $title,
        public readonly string $body,
    ) {}

    public function handle(PushService $push): void
    {
        // مثلِ `SendPushJob`: false فقط وقتی می‌آید که هیچ دستگاهی در این تکه
        // پیام را نگرفته — پس retry نمی‌تواند به کسی دو بار پوش بدهد.
        if ($push->sendToUsers($this->userIds, $this->title, $this->body) === false) {
            Log::warning('SendBulkPushJob: هیچ پوشی در این تکه تحویل نشد، تلاش مجدد.', ['users' => count($this->userIds)]);

            throw new RuntimeException('Bulk push delivery failed for a chunk of '.count($this->userIds).' users');
        }
    }
}
