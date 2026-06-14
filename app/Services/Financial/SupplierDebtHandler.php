<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Models\Wallet;
use App\Services\SmsService;
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
        protected SmsService $smsService,
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

        $order->update([
            Order::SUPPLIER_DEBT_PENDING => true,
            Order::SUPPLIER_DEBT_AMOUNT => $reclaimAmount,
        ]);

        $supplierUser = $order->items->first()?->supplier;
        if ($supplierUser?->mobile) {
            $this->smsService->send(
                $supplierUser->mobile,
                __('sms.supplier_debt_notification', [
                    'order_number' => $order->order_number,
                    'amount' => number_format((int) $reclaimAmount),
                ])
            );
        }

        Log::channel('financial')->warning(
            "Supplier {$supplierUser?->id} insufficient balance for deficit reclaim on order {$order->id}. Debt: {$reclaimAmount}"
        );

        return 'deficit_pending_supplier';
    }
}
