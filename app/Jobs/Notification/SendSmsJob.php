<?php

namespace App\Jobs\Notification;

use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * تعداد تلاش مجدد در صورت شکست
     */
    public $tries = 3;

    /**
     * فاصله‌های زمانی (ثانیه) بین تلاش‌های مجدد: 30 ثانیه، 60 ثانیه، 2 دقیقه.
     * Exponential back-off: اگر TSMS موقتاً در دسترس نباشد، درخواست‌ها انباشته نمی‌شوند.
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 60, 120];

    /**
     * حداکثر یک exception ثبت‌نشده مجاز است (از infinite retry loop جلوگیری می‌کند).
     */
    public int $maxExceptions = 1;

    public function __construct(protected string $mobile, protected string $message) {}

    public function handle(SmsService $smsService): void
    {
        try {
            $smsService->send($this->mobile, $this->message);
        } catch (\Exception $e) {
            Log::error("SMS Job Failed for {$this->mobile}: ".$e->getMessage());
            throw $e;
        }
    }
}
