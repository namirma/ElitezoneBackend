<?php

namespace App\Listeners\Notification;

use App\Jobs\Notification\SendSmsJob;
use App\Models\SmsTemplate;
use App\Sms\EventCatalog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * یک لیسنر برای همه‌ی رویدادهای `EventCatalog` — قالب‌های سفارشیِ فعالِ ادمین
 * را که به همین رویداد و مخاطب بسته شده‌اند می‌فرستد.
 *
 * این همان «شرطِ جدید با پیامکِ جدید بدونِ کد» است: ادمین قالبی با
 * `event = OrderPaid::class` و `audience = supplier` می‌سازد و از این‌جا به بعد
 * هر پرداختِ سفارش، به تأمین‌کننده‌اش همان متن را می‌فرستد. شرط‌های عمومی
 * (نقش، حداقل مبلغ، ساعت سکوت) از خودِ قالب می‌آیند — `SendSmsJob::template`
 * همان مسیرِ پیامک‌های built-in است.
 *
 * صف‌دار، مثلِ شش لیسنرِ اعلانِ دیگر: چند کوئریِ گیرنده نباید داخلِ درخواستِ
 * پرداخت اجرا شود.
 */
class SendCustomSmsForEvent implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(object $event): void
    {
        $templates = array_filter(
            SmsTemplate::allCached(),
            fn (SmsTemplate $t) => $t->{SmsTemplate::IS_CUSTOM}
                && $t->{SmsTemplate::IS_ACTIVE}
                && $t->{SmsTemplate::EVENT} === $event::class,
        );

        foreach ($templates as $template) {
            $recipients = EventCatalog::recipients($event, (string) $template->{SmsTemplate::AUDIENCE});

            foreach ($recipients as $user) {
                SendSmsJob::template(
                    $user->mobile,
                    $template->{SmsTemplate::KEY},
                    EventCatalog::params($event, $user),
                );
            }
        }
    }
}
