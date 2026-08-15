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

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $inviteModel
     *                                                                          مدلِ دعوت — دو سمتِ جریان دو جدول دارند
     *                                                                          (`employer_sms_invites` / `elite_yar_sms_invites`) ولی
     *                                                                          چرخه‌ی عمرشان یکی است، پس یک job هر دو را می‌برد.
     */
    public function __construct(
        protected int $inviteId,
        protected string $mobile,
        protected string $message,
        protected string $inviteModel = EmployerSmsInvite::class,
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
     * تا الیت یار بتواند مجدداً اقدام کند و ظرفیت ۱۰ دعوتش تلف نشود.
     */
    public function failed(\Throwable $e): void
    {
        $this->inviteModel::find($this->inviteId)?->delete();

        Log::error("SendInviteSmsJob permanently failed for {$this->mobile}: {$e->getMessage()}");
    }
}
