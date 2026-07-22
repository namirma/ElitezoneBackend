<?php

namespace App\Jobs\Financial;

use App\Constants\FinancialCodes;
use App\Jobs\Notification\SendSmsJob;
use App\Models\Order;
use App\Models\Wallet;
use App\Services\Financial\WalletTransactionEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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

    public function handle(WalletTransactionEngine $walletEngine): void
    {
        // chunkById() processes one batch at a time (memory-safe at scale) while
        // still supporting eager loading — unlike cursor() which can't eager-load.
        Order::where(Order::SUPPLIER_DEBT_PENDING, true)
            ->with(['items.supplier.wallet'])
            ->chunkById(100, function ($pendingOrders) use ($walletEngine) {
                foreach ($pendingOrders as $order) {
                    try {
                        $this->processOrder($order, $walletEngine);
                    } catch (\Exception $e) {
                        Log::channel('financial')->error("CheckSupplierBalancesJob error for order {$order->id}: ".$e->getMessage());
                    }
                }
            });
    }

    private function processOrder(Order $order, WalletTransactionEngine $walletEngine): void
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
            $order->forceFill([
                Order::SUPPLIER_DEBT_PENDING => false,
                Order::SUPPLIER_DEBT_AMOUNT => 0,
            ])->save();

            return;
        }

        if ((float) $supplierUser->wallet->balance < $debtAmount) {
            return; // Still insufficient — try again next run
        }

        // Supplier now has enough — process the reclaim and release the buyer hold
        DB::transaction(function () use ($order, $debtAmount, $supplierUser, $walletEngine) {

            // Re-lock the order and supplier wallet
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->first();
            if (! $lockedOrder || ! $lockedOrder->{Order::SUPPLIER_DEBT_PENDING}) {
                return; // Already handled by a concurrent run
            }

            $supplierWallet = Wallet::where('user_id', $supplierUser->id)->lockForUpdate()->first();
            if (! $supplierWallet || (float) $supplierWallet->balance < $debtAmount) {
                return; // Balance dropped below threshold since the check — skip
            }

            // 1. Reclaim the debt from the supplier into the system wallet: WS(−) → WSSG(+).
            //    Routed through WalletTransactionEngine so BOTH legs leave an auditable
            //    WalletTransaction (the previous raw increment/decrement left no trail).
            $reclaimDescription = __('wallet.descriptions.supplier_debt_reclaim', [
                'order_number' => $lockedOrder->order_number,
            ]);

            $walletEngine->debitSupplier(
                $lockedOrder, $debtAmount, FinancialCodes::STATUS_AVAILABLE, $supplierUser->id, $reclaimDescription
            );
            $walletEngine->creditSystem(
                $lockedOrder, $debtAmount, FinancialCodes::STATUS_AVAILABLE, $reclaimDescription
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
                    // Move balance_hold → balance AND record an append-only
                    // TYPE_HOLD_RELEASE ledger row (the previous code mutated the hold
                    // row + bare increment/decrement, leaving no distinct release event).
                    $walletEngine->releaseHold(
                        (int) $lockedOrder->placer_user_id,
                        (float) $holdTx->amount,
                        $lockedOrder,
                        __('wallet.descriptions.buyer_refund_released', [
                            'order_number' => $lockedOrder->order_number,
                        ]),
                        $holdTx,
                    );

                    // Mark the original hold row settled so it is no longer found as an
                    // outstanding hold (the release row above is the audit event).
                    $holdTx->update(['status' => FinancialCodes::STATUS_AVAILABLE]);

                    // Notify buyer — dispatched AFTER the transaction commits (afterCommit)
                    // so the blocking SMS HTTP call never runs while this job holds the
                    // order + both wallet row locks, and no "refund released" SMS is sent
                    // if the transaction later rolls back.
                    $buyer = $buyerWallet->user;
                    if ($buyer?->mobile) {
                        SendSmsJob::dispatch(
                            $buyer->mobile,
                            __('sms.buyer_refund_released', [
                                'amount' => number_format((int) $holdTx->amount),
                                'order_number' => $lockedOrder->order_number,
                            ])
                        )->afterCommit();
                    }
                }
            }

            // 3. Clear the pending debt flag on the order
            $lockedOrder->forceFill([
                Order::SUPPLIER_DEBT_PENDING => false,
                Order::SUPPLIER_DEBT_AMOUNT => 0,
            ])->save();

            Log::channel('financial')->info("Supplier debt cleared for order {$lockedOrder->id}. Debt: {$debtAmount}");
        });
    }
}
