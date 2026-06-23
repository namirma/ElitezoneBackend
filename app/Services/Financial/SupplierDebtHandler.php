<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Constants\FinancialCodes;
use App\Jobs\Notification\SendSmsJob;
use App\Models\Order;
use App\Models\Wallet;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the deficit leg of a weighbridge result.
 *
 * Called only when POOM < POO (under-delivery). There are two outcomes:
 *   - Supplier covers the delta immediately → buyer refund lands as AVAILABLE.
 *   - Supplier cannot cover now → buyer refund is parked in HOLD and the order
 *     is flagged for CheckSupplierBalancesJob to release later.
 *
 * Must be called inside an active DB::transaction with a locked Order.
 * Returns the status string for the orchestrator response.
 */
class SupplierDebtHandler
{
    public function __construct(
        protected WalletTransactionEngine $walletEngine,
    ) {}

    /**
     * @return 'deficit'|'deficit_pending_supplier'
     */
    public function handle(Order $order, float $refundAmount, float $reclaimAmount): string
    {
        $supplierId = (int) $order->items->first()->supplier_user_id;

        // Saze already received POO at payment time; it pays back the over-paid portion.
        $this->walletEngine->debitSystem(
            $order, $refundAmount, FinancialCodes::STATUS_AVAILABLE,
            "پرداخت وجه کسری باسکول سفارش {$order->order_number} به خریدار"
        );

        // Pessimistic lock on the supplier wallet to avoid a race with other jobs.
        $supplierWallet = Wallet::where('user_id', $supplierId)->lockForUpdate()->first();
        $supplierCanCover = $supplierWallet && ((float) $supplierWallet->balance >= $reclaimAmount);

        if ($supplierCanCover) {
            return $this->settleImmediately($order, $refundAmount, $reclaimAmount, $supplierId);
        }

        return $this->parkDebtAndNotify($order, $refundAmount, $reclaimAmount);
    }

    private function settleImmediately(
        Order $order,
        float $refundAmount,
        float $reclaimAmount,
        int $supplierId,
    ): string {
        $this->walletEngine->creditBuyer(
            $order, $refundAmount, FinancialCodes::STATUS_AVAILABLE, 'بازگشت وجه ناشی از کسری باسکول'
        );
        $this->walletEngine->debitSupplier(
            $order, $reclaimAmount, FinancialCodes::STATUS_AVAILABLE, $supplierId, 'کسر بابت کسری باسکول'
        );
        $this->walletEngine->creditSystem(
            $order, $reclaimAmount, FinancialCodes::STATUS_AVAILABLE,
            "بازپس‌گیری از تامین‌کننده بابت کسری باسکول سفارش {$order->order_number}"
        );

        return 'deficit';
    }

    private function parkDebtAndNotify(
        Order $order,
        float $refundAmount,
        float $reclaimAmount,
    ): string {
        // Buyer refund parks in HOLD until the supplier tops up their wallet.
        $this->walletEngine->creditBuyer(
            $order, $refundAmount, FinancialCodes::STATUS_HOLD,
            'بازگشت وجه ناشی از کسری باسکول (در انتظار تسویه سوپلایر)'
        );

        $order->forceFill([
            Order::SUPPLIER_DEBT_PENDING => true,
            Order::SUPPLIER_DEBT_AMOUNT => $reclaimAmount,
        ])->save();

        // Queue the SMS and defer it until AFTER the surrounding weighbridge transaction
        // commits. The previous synchronous SmsService::send() was a blocking HTTP POST
        // executed while the order + wallet rows were lockForUpdate-held — a slow TSMS
        // endpoint would hold those locks for the whole network round-trip, and a later
        // rollback would have already told the supplier about a debt that never landed.
        $supplierUser = $order->items->first()?->supplier;
        if ($supplierUser?->mobile) {
            SendSmsJob::dispatch(
                $supplierUser->mobile,
                __('sms.supplier_debt_notification', [
                    'order_number' => $order->order_number,
                    'amount' => number_format((int) $reclaimAmount),
                ])
            )->afterCommit();
        }

        Log::channel('financial')->warning(
            "Supplier {$supplierUser?->id} insufficient balance for deficit reclaim on order {$order->id}. Debt: {$reclaimAmount}"
        );

        return 'deficit_pending_supplier';
    }
}
