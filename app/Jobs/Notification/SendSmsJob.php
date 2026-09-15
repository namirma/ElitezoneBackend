<?php

namespace App\Jobs\Notification;

use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Bus\PendingDispatch;
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

    /**
     * دو حالت:
     *  - متنِ خام: `new SendSmsJob($mobile, $message)` — همان قراردادِ قدیمی.
     *  - قالب: `SendSmsJob::template($mobile, 'order_placed', [...])` — متن در
     *    **لحظه‌ی اجرا** از `SmsTemplate::render()` می‌آید، پس ویرایشِ ادمین روی
     *    پیامکِ در صف هم اثر می‌کند، و خاموش‌بودن/شرط همان‌جا تصمیم گرفته می‌شود.
     *
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        protected string $mobile,
        protected string $message,
        protected ?string $templateKey = null,
        protected array $params = [],
    ) {}

    /**
     * ارسال از روی قالب — تنها راهِ درست برای پیامک‌هایی که کلیدِ lang دارند.
     *
     * `PendingDispatch` برمی‌گردد تا `->afterCommit()` مثلِ قبل قابلِ زنجیره باشد.
     * ساعتِ سکوتِ قالب همین‌جا به `delay` تبدیل می‌شود، نه با `release()` داخلِ
     * job — چون `release()` شمارنده‌ی `tries` را می‌خورد و یک شبِ کامل سه تلاش
     * را تمام می‌کند.
     *
     * @param  array<string, mixed>  $params
     */
    public static function template(string $mobile, string $key, array $params = []): PendingDispatch
    {
        $pending = static::dispatch($mobile, '', $key, $params);

        $until = SmsTemplate::quietHoursDelay($key);

        if ($until !== null) {
            $pending->delay($until);
        }

        return $pending;
    }

    public function handle(SmsService $smsService): void
    {
        $message = $this->resolveMessage();

        if ($message === null) {
            return;
        }

        // ضدِ ارسالِ تکراری: یک پنجرهٔ کوتاه (پیش‌فرض ۲۵ ثانیه) که «همان پیام به همان
        // شماره» را دوباره نمی‌فرستد. این پنجره عمداً کوچک‌تر از اولین backoffِ صف (۳۰s)
        // و کوتاه‌تر از سقفِ throttleِ ارسال (۲/دقیقه) است، پس هرگز یک ارسالِ مجددِ
        // مشروعِ OTP را (که فقط پس از ۳۰ ثانیه ممکن است) سرکوب نمی‌کند و صرفاً
        // dispatchِ لحظه‌ایِ دوباره / retryِ همان لحظه را حذف می‌کند.
        $window = (int) config('services.tsms.dedupe_window_seconds', 25);
        $dedupeKey = 'sms:sent:'.md5($this->mobile.'|'.$message);

        // Cache::add اتمیک است: اگر کلید از قبل باشد false می‌دهد (یعنی همین پیام تازه رفته).
        if ($window > 0 && ! Cache::add($dedupeKey, true, now()->addSeconds($window))) {
            Log::info("SMS dedupe: skipped duplicate within {$window}s window for {$this->mobile}");

            return;
        }

        try {
            // مقدار بازگشتی باید بررسی شود: send() روی شکستِ گیت‌وی false می‌دهد (نه exception)؛
            // نادیده‌گرفتنِ آن باعث می‌شد پیامکِ گم‌شده «موفق» تلقی شود و tries/backoff هرگز اجرا نشود.
            $result = $smsService->send($this->mobile, $message, $this->templateKey);

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

    /**
     * متنِ خام همان است که هست؛ قالب در لحظه رندر می‌شود و ردشدنش لاگ می‌گیرد
     * — ادمین باید بتواند ببیند «چرا نرفت»، نه فقط «چه رفت».
     */
    private function resolveMessage(): ?string
    {
        if ($this->templateKey === null) {
            return $this->message;
        }

        $message = SmsTemplate::render($this->templateKey, $this->params, $this->mobile);

        if ($message !== null) {
            return $message;
        }

        // ردیفِ حذف‌شده‌ی یک قالبِ سفارشی (بدونِ متنِ lang) هم «خاموش» است، نه «شرط».
        $template = SmsTemplate::findCached($this->templateKey);
        $status = $template === null || ! $template->{SmsTemplate::IS_ACTIVE}
            ? SmsLog::STATUS_SKIPPED_INACTIVE
            : SmsLog::STATUS_SKIPPED_CONDITION;

        SmsLog::record(
            $this->mobile,
            $this->templateKey,
            (string) (SmsTemplate::text($this->templateKey, $this->params) ?? ''),
            $status,
        );

        return null;
    }
}
