<?php

namespace App\Jobs\Financial;

use App\Models\Order;
use App\Services\Financial\SupplierDebtHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Monitor supplier wallets and release buyer refund holds when a supplier
 * that owed a weighbridge-deficit debt has topped up their wallet.
 *
 * Context — deficit weighbridge flow (phase 8):
 *   If at weighbridge time the supplier's wallet cannot cover (PTS − PTSM),
 *   SupplierDebtHandler moves **no money at all**. It only records what is
 *   owed in each direction: `supplier_debt_amount` (owed by the supplier) and
 *   `buyer_refund_pending_amount` (owed to the buyer).
 *
 *   This job runs periodically and hands each such order to
 *   `SupplierDebtHandler::settlePending()`, which reclaims the debt (WS → WSSG)
 *   and only then pays the buyer (WSSG → WO), in one locked transaction.
 *
 * ⚠️ The settlement body lives in the handler, not here: `POST /profile/debts/repay`
 * runs the very same method when the supplier pays from the Debts tab. Two copies
 * are exactly how the reclaim once ran twice for one debt.
 */
class CheckSupplierBalancesJob implements ShouldBeUnique, ShouldQueue
{
    /**
     * جابِ زمان‌بندی‌شدهٔ singleton — قفلِ سراسری تا دو نمونه (به‌دلیل backlog صف یا
     * cadenceِ هر-دقیقه) هم‌زمان اجرا نشوند. ۱۰ دقیقه سقفِ نگه‌داریِ قفل است.
     */
    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'check-supplier-balances';
    }

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * اگر بررسی موجودی سوپلایرها با خطا مواجه شد، یک بار دیگر بعد از ۲ دقیقه تلاش می‌کند.
     * این job در Kernel::schedule() زمان‌بندی شده و اجرای مجدد از دست نمی‌رود.
     */
    public int $tries = 2;

    public array $backoff = [120];

    public function handle(SupplierDebtHandler $debtHandler): void
    {
        // chunkById() processes one batch at a time (memory-safe at scale) and stays
        // correct while the loop clears the very flag it filters on.
        Order::where(Order::SUPPLIER_DEBT_PENDING, true)
            ->chunkById(100, function ($pendingOrders) use ($debtHandler) {
                foreach ($pendingOrders as $order) {
                    /** @var Order $order */
                    try {
                        // false = «هنوز نه» (موجودی کم، یا دفتر دیگر باز نیست) — اجرای بعدی.
                        $debtHandler->settlePending($order);
                    } catch (\Exception $e) {
                        Log::channel('financial')->error("CheckSupplierBalancesJob error for order {$order->id}: ".$e->getMessage());
                    }
                }
            });
    }
}
