<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Order\OrderCancelRequestAction;
use App\Models\OrderCancelRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * درخواستِ لغوی که طرفِ مقابل در ۴۸ ساعت به آن پاسخ نداد → صفِ بک‌آفیس.
 *
 * ⚠️ **بی‌پاسخی = ارجاع، نه پذیرش و نه رد.** مدیریت مهلت را تعیین کرد ولی نگفت
 * پس از آن چه شود (سند ابهامات، بند ۴). این انتخابِ ماست و سمتِ محافظه‌کارانه
 * است: پذیرشِ خودکار پول جابه‌جا می‌کند بی‌آنکه آدمی تصمیم گرفته باشد، و ردِ
 * خودکار درخواست‌کننده را بی‌جواب رها می‌کند. ارجاع هیچ‌کدام را نمی‌کند — فقط
 * تصمیم را به کسی می‌سپارد که مدیریت گفته حرفِ آخر را می‌زند.
 *
 * ساعتی، چون مهلت با ساعت سنجیده می‌شود — همان انتخابِ `orders:expire-overweight`.
 */
class EscalateCancelRequests extends Command
{
    protected $signature = 'orders:escalate-cancel-requests';

    protected $description = 'Escalate two-sided cancel requests whose 48-hour response window passed unanswered';

    public function handle(OrderCancelRequestAction $action): int
    {
        $escalated = 0;

        OrderCancelRequest::query()
            ->where(OrderCancelRequest::STATUS, OrderCancelRequest::STATUS_PENDING)
            ->where(OrderCancelRequest::RESPOND_UNTIL, '<=', now())
            ->chunkById(100, function ($requests) use ($action, &$escalated) {
                foreach ($requests as $request) {
                    try {
                        if ($action->escalateTimedOut($request)) {
                            $escalated++;
                        }
                    } catch (\Throwable $e) {
                        // یک ردیفِ خراب نباید بقیه را متوقف کند.
                        Log::channel('orders')->error(
                            'orders:escalate-cancel-requests failed for request '.$request->getKey().': '.$e->getMessage()
                        );
                    }
                }
            });

        $this->info("Escalated cancel requests: {$escalated}");

        return self::SUCCESS;
    }
}
