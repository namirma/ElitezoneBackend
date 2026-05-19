<?php

namespace App\Actions\Order;

use App\Models\Order;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Refunds the paid amount of an order back to the placer's wallet.
 *
 * Extracted from HasOrderActions::refundToWallet so that financial logic
 * lives in an Action (single-responsibility) rather than a Model trait.
 *
 * Uses pessimistic locking (lockForUpdate) to prevent race conditions when
 * two concurrent requests try to refund the same order simultaneously.
 */
final class RefundOrderToWalletAction
{
    public function execute(Order $order): void
    {
        // Guard: nothing to refund if no payment was made
        if (
            $order->payment_status !== Order::PAYMENT_PAID &&
            $order->partial_payment_amount <= 0
        ) {
            return;
        }

        DB::transaction(function () use ($order): void {
            // Lock the order row to prevent double-refund race conditions
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked || $locked->payment_status === Order::PAYMENT_REFUNDED) {
                return;
            }

            $refundAmount = ($locked->payment_status === Order::PAYMENT_PAID)
                ? $locked->final_amount
                : $locked->partial_payment_amount;

            // Lock wallet row as well before incrementing
            $wallet = $locked->placer->wallet()->lockForUpdate()->firstOrCreate([]);

            $wallet->increment(Wallet::BALANCE, $refundAmount);

            $wallet->transactions()->create([
                WalletTransaction::AMOUNT => $refundAmount,
                WalletTransaction::TYPE => WalletTransaction::TYPE_DEPOSIT,
                WalletTransaction::STATUS => WalletTransaction::STATUS_CONFIRMED,
                WalletTransaction::DESCRIPTION => __('wallet.descriptions.refund_order', [
                    'order_number' => $locked->order_number,
                ]),
                WalletTransaction::META => [
                    'order_id' => $locked->id,
                    'reason' => 'order_cancellation',
                ],
            ]);

            $locked->update(['payment_status' => Order::PAYMENT_REFUNDED]);
        });
    }
}
