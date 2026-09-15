<?php

namespace App\Jobs\Notification;

use App\Models\EmployerSmsInvite;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendInviteSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public array $backoff = [30, 60, 120];

    /**
     * @param  class-string<Model>  $inviteModel
     *                                            مدلِ دعوت — دو سمتِ جریان دو جدول دارند
     *                                            (`employer_sms_invites` / `elite_yar_sms_invites`) ولی
     *                                            چرخه‌ی عمرشان یکی است، پس یک job هر دو را می‌برد.
     * @param  string|null  $templateKey  کلیدِ `sms_templates` — متن در لحظه‌ی اجرا رندر می‌شود
     *                                    (مثل `SendSmsJob::template()`)؛ null یعنی `$message` خام است.
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        protected int $inviteId,
        protected string $mobile,
        protected string $message,
        protected string $inviteModel = EmployerSmsInvite::class,
        protected ?string $templateKey = null,
        protected array $params = [],
    ) {}

    public function handle(SmsService $smsService): void
    {
        $message = $this->message;

        if ($this->templateKey !== null) {
            $message = SmsTemplate::render($this->templateKey, $this->params, $this->mobile);

            // قالب خاموش/ردشده: دعوت نرفته، پس رکوردش هم نباید سهمیه‌ی ۱۰ دعوت را بخورد.
            if ($message === null) {
                SmsLog::record(
                    $this->mobile,
                    $this->templateKey,
                    (string) (SmsTemplate::text($this->templateKey, $this->params) ?? ''),
                    SmsLog::STATUS_SKIPPED_INACTIVE,
                );
                $this->inviteModel::find($this->inviteId)?->delete();

                return;
            }
        }

        $result = $smsService->send($this->mobile, $message, $this->templateKey);

        if ($result === false) {
            throw new \RuntimeException("SMS gateway returned failure for {$this->mobile}");
        }
    }

    /**
     * اگر بعد از تمام تلاش‌ها ارسال پیامک ناموفق بود، رکورد دعوت حذف می‌شود
     * تا الیت یار بتواند مجدداً اقدام کند و ظرفیت ۱۰ دعوتش تلف نشود.
     */
    public function failed(\Throwable $e): void
    {
        $this->inviteModel::find($this->inviteId)?->delete();

        Log::error("SendInviteSmsJob permanently failed for {$this->mobile}: {$e->getMessage()}");
    }
}
