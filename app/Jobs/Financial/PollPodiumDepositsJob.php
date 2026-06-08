<?php

namespace App\Jobs\Financial;

use App\Actions\Financial\ProcessPodiumDepositAction;
use App\Services\PodiumServices\DepositStatementService;
use Hekmatinasser\Verta\Verta;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Job polling صورت‌حساب سپرده پادیوم
 *
 * هر ۵ دقیقه اجرا می‌شود.
 * پنجره زمانی: ۱۵ دقیقه قبل تا لحظه حال — با overlap برای PAYA که کمی تأخیر دارد.
 *
 * ایمنی:
 * - withoutOverlapping() در Kernel → اجرای موازی ندارد
 * - ProcessPodiumDepositAction هر DocNumber را فقط یک‌بار پردازش می‌کند (idempotent)
 * - خطای هر statement به صورت isolated log می‌شود — statement‌های بعدی پردازش می‌شوند
 */
class PollPodiumDepositsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** تعداد دفعات retry در صورت خطا */
    public int $tries = 3;

    /** تأخیر retry */
    public int $backoff = 60;

    public function handle(
        DepositStatementService $statementService,
        ProcessPodiumDepositAction $processAction,
    ): void {
        $to   = Carbon::now();
        $from = $to->copy()->subMinutes(15); // پنجره ۱۵ دقیقه‌ای با overlap

        // تبدیل به تاریخ شمسی برای API پادیوم
        $fromJalali = Verta::instance($from)->format('Y/m/d');
        $fromTime   = $from->format('H:i:s');
        $toJalali   = Verta::instance($to)->format('Y/m/d');
        $toTime     = $to->format('H:i:s');

        Log::info('PollPodiumDepositsJob: شروع polling', [
            'from' => "{$fromJalali} {$fromTime}",
            'to'   => "{$toJalali} {$toTime}",
        ]);

        try {
            $deposits = $statementService->getIncomingDeposits(
                fromDate: $fromJalali,
                toDate:   $toJalali,
                fromTime: $fromTime,
                toTime:   $toTime,
            );
        } catch (\Throwable $e) {
            Log::error('PollPodiumDepositsJob: خطا در دریافت صورت‌حساب از پادیوم', [
                'error' => $e->getMessage(),
            ]);
            // job را fail می‌کنیم تا retry انجام شود
            $this->fail($e);

            return;
        }

        $total     = count($deposits);
        $processed = 0;
        $errors    = 0;

        foreach ($deposits as $statement) {
            try {
                $processAction->execute($statement);
                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                Log::error('PollPodiumDepositsJob: خطا در پردازش statement', [
                    'error'      => $e->getMessage(),
                    'doc_number' => $statement['DocNumber'] ?? 'N/A',
                ]);
                // ادامه می‌دهیم — یک statement خراب، بقیه را مسدود نمی‌کند
            }
        }

        Log::info('PollPodiumDepositsJob: پایان polling', [
            'total_incoming' => $total,
            'processed'      => $processed,
            'errors'         => $errors,
        ]);
    }
}
