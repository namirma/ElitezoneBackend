<?php

namespace App\Jobs\Notification;

use App\Models\EmployerSmsInvite;
use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendInviteSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public array $backoff = [30, 60, 120];

    public int $maxExceptions = 1;

    public function __construct(
        protected int $inviteId,
        protected string $mobile,
        protected string $message,
    ) {}

    public function handle(SmsService $smsService): void
    {
        $result = $smsService->send($this->mobile, $this->message);

        if ($result === false) {
            throw new \RuntimeException("SMS gateway returned failure for {$this->mobile}");
        }
    }

    /**
     * اگر بعد از تمام تلاش‌ها ارسال پیامک ناموفق بود، رکورد دعوت حذف می‌شود
     * تا مشاور بتواند مجدداً اقدام کند و ظرفیت ۱۰ دعوتش تلف نشود.
     */
    public function failed(\Throwable $e): void
    {
        EmployerSmsInvite::find($this->inviteId)?->delete();

        Log::error("SendInviteSmsJob permanently failed for {$this->mobile}: {$e->getMessage()}");
    }
}
