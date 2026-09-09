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
 * ارسالِ پوش خارج از چرخهٔ درخواست — همان دلیلِ `SendSmsJob`: هیچ چیز روی
 * مسیرِ پاسخ نباید منتظرِ یک سرویسِ بیرونی بماند.
 */
class SendPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    /**
     * back-off نمایی، هم‌ردیفِ SendSmsJob: پروویدرِ موقتاً در دسترس نبودن
     * نباید صف را با تلاش‌های پشت‌سرهم اشباع کند.
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 60, 120];

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        protected int $userId,
        protected string $title,
        protected string $body,
        protected array $data = [],
    ) {}

    public function handle(PushService $push): void
    {
        // sendToUser روی شکستِ گیت‌وی false می‌دهد (نه exception)؛ بدون این
        // بررسی، ارسالِ گم‌شده «موفق» تلقی می‌شد و tries/backoff هرگز اجرا نمی‌شد.
        if ($push->sendToUser($this->userId, $this->title, $this->body, $this->data) === false) {
            Log::warning('SendPushJob: تحویلِ پوش ناموفق بود، تلاش مجدد.', ['user_id' => $this->userId]);

            throw new RuntimeException("Push delivery failed for user {$this->userId}");
        }
    }
}
