<?php

namespace App\Jobs\Notification;

use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
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

    public function __construct(protected string $mobile, protected string $message) {}

    public function handle(SmsService $smsService): void
    {
        // ضدِ ارسالِ تکراری: یک پنجرهٔ کوتاه (پیش‌فرض ۲۵ ثانیه) که «همان پیام به همان
        // شماره» را دوباره نمی‌فرستد. این پنجره عمداً کوچک‌تر از اولین backoffِ صف (۳۰s)
        // و کوتاه‌تر از سقفِ throttleِ ارسال (۲/دقیقه) است، پس هرگز یک ارسالِ مجددِ
        // مشروعِ OTP را (که فقط پس از ۳۰ ثانیه ممکن است) سرکوب نمی‌کند و صرفاً
        // dispatchِ لحظه‌ایِ دوباره / retryِ همان لحظه را حذف می‌کند.
        $window = (int) config('services.tsms.dedupe_window_seconds', 25);
        $dedupeKey = 'sms:sent:'.md5($this->mobile.'|'.$this->message);

        // Cache::add اتمیک است: اگر کلید از قبل باشد false می‌دهد (یعنی همین پیام تازه رفته).
        if ($window > 0 && ! Cache::add($dedupeKey, true, now()->addSeconds($window))) {
            Log::info("SMS dedupe: skipped duplicate within {$window}s window for {$this->mobile}");

            return;
        }

        try {
            // مقدار بازگشتی باید بررسی شود: send() روی شکستِ گیت‌وی false می‌دهد (نه exception)؛
            // نادیده‌گرفتنِ آن باعث می‌شد پیامکِ گم‌شده «موفق» تلقی شود و tries/backoff هرگز اجرا نشود.
            $result = $smsService->send($this->mobile, $this->message);

            if ($result === false) {
                throw new \RuntimeException("SMS gateway returned failure for {$this->mobile}");
            }
        } catch (\Throwable $e) {
            // شکستِ واقعی: کلیدِ dedupe را آزاد کن تا retry بتواند دوباره تلاش کند.
            Cache::forget($dedupeKey);
            Log::error("SMS Job Failed for {$this->mobile}: ".$e->getMessage());
            throw $e;
        }
    }
}
