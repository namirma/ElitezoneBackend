<?php

namespace App\Jobs\Financial;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Models\Wallet;
use App\Services\Financial\WalletTransactionEngine;
use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Monitor supplier wallets and release buyer refund holds when a supplier
 * that owed a weighbridge-deficit debt has topped up their wallet.
 *
 * Context — deficit weighbridge flow:
 *   If at weighbridge time the supplier's wallet did not have enough balance
 *   to cover (PTS − PTSM), ProcessWeighbridgeResultAction:
 *     1. Sets order.supplier_debt_pending = true and records the owed amount
 *     2. Credits the buyer's wallet with the refund but in HOLD status
 *
 *   This job runs periodically and, for each such order:
 *     - Checks the supplier wallet balance
 *     - If balance >= supplier_debt_amount: reclaims the debt, releases the
 *       buyer hold to AVAILABLE, and notifies the buyer via SMS
 */
class CheckSupplierBalancesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * اگر بررسی موجودی سوپلایرها با خطا مواجه شد، یک بار دیگر بعد از ۲ دقیقه تلاش می‌کند.
     * این job در Kernel::schedule() زمان‌بندی شده و اجرای مجدد از دست نمی‌رود.
     */
    public int $tries = 2;

    public array $backoff = [120];

    public function handle(SmsService $smsService, WalletTransactionEngine $walletEngine): void
    {
        // Only look at orders with a pending supplier debt
        $pendingOrders = Order::where(Order::SUPPLIER_DEBT_PENDING, true)
            ->with(['items.supplier.wallet'])
            ->get();

        foreach ($pendingOrders as $order) {
            try {
                $this->processOrder($order, $smsService, $walletEngine);
            } catch (\Exception $e) {
                Log::error("CheckSupplierBalancesJob error for order {$order->id}: ".$e->getMessage());
            }
        }
    }

    private function processOrder(Order $order, SmsService $smsService, WalletTransactionEngine $walletEngine): void
    {
        $firstItem = $order->items->first();
        if (! $firstItem) {
            return;
        }

        $supplierUser = $firstItem->supplier;
        if (! $supplierUser || ! $supplierUser->wallet) {
            return;
        }

        $debtAmount = (float) $order->{Order::SUPPLIER_DEBT_AMOUNT};
        if ($debtAmount <= 0) {
            // Debt already cleared or zero — just unflag
            $order->update([
                Order::SUPPLIER_DEBT_PENDING => false,
                Order::SUPPLIER_DEBT_AMOUNT => 0,
            ]);

            return;
        }

        if ((float) $supplierUser->wallet->balance < $debtAmount) {
            return; // Still insufficient — try again next run
        }

        // Supplier now has enough — process the reclaim and release the buyer hold
        DB::transaction(function () use ($order, $debtAmount, $supplierUser, $smsService, $walletEngine) {

            // Re-lock the order and supplier wallet
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->first();
            if (! $lockedOrder || ! $lockedOrder->{Order::SUPPLIER_DEBT_PENDING}) {
                return; // Already handled by a concurrent run
            }

            $supplierWallet = Wallet::where('user_id', $supplierUser->id)->lockForUpdate()->first();
            if (! $supplierWallet || (float) $supplierWallet->balance < $debtAmount) {
                return; // Balance dropped below threshold since the check — skip
            }

            $systemUserId = (int) config('financial.system_user_id', 1);

            // 1. Reclaim the debt from the supplier into the system wallet: WS(−) → WSSG(+).
            //    Routed through WalletTransactionEngine so BOTH legs leave an auditable
            //    WalletTransaction (the previous raw increment/decrement left no trail).
            $reclaimDescription = __('wallet.descriptions.supplier_debt_reclaim', [
                'order_number' => $lockedOrder->order_number,
            ]);

            $walletEngine->process(
                '-', $debtAmount, FinancialCodes::WALLET_SUPPLIER, FinancialCodes::STATUS_AVAILABLE,
                $lockedOrder, $reclaimDescription, $supplierUser->id,
            );
            $walletEngine->process(
                '+', $debtAmount, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_AVAILABLE,
                $lockedOrder, $reclaimDescription, $systemUserId,
            );

            // 2. Release the buyer's parked refund: WO balance_hold(−) → WO balance(+)
            $buyerWallet = Wallet::where('user_id', $lockedOrder->placer_user_id)->lockForUpdate()->first();
            if ($buyerWallet && $buyerWallet->balance_hold >= $debtAmount) {
                // Find the specific hold transaction for this order on the buyer wallet
                $holdTx = $buyerWallet->transactions()
                    ->where('order_id', $lockedOrder->id)
                    ->where('type', 'deposit')
                    ->where('status', FinancialCodes::STATUS_HOLD)
                    ->latest()
                    ->first();

                if ($holdTx) {
                    $holdTx->update(['status' => FinancialCodes::STATUS_AVAILABLE]);
                    $buyerWallet->decrement('balance_hold', $holdTx->amount);
                    $buyerWallet->increment('balance', $holdTx->amount);

                    // Notify buyer
                    $buyer = $buyerWallet->user;
                    if ($buyer?->mobile) {
                        $smsService->send(
                            $buyer->mobile,
                            __('sms.buyer_refund_released', [
                                'amount' => number_format((int) $holdTx->amount),
                                'order_number' => $lockedOrder->order_number,
                            ])
                        );
                    }
                }
            }

            // 3. Clear the pending debt flag on the order
            $lockedOrder->update([
                Order::SUPPLIER_DEBT_PENDING => false,
                Order::SUPPLIER_DEBT_AMOUNT => 0,
            ]);

            Log::info("Supplier debt cleared for order {$lockedOrder->id}. Debt: {$debtAmount}");
        });
    }
}
