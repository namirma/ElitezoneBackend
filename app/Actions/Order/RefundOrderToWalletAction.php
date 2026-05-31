<?php

namespace App\Actions\Order;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Services\Financial\WalletTransactionEngine;
use Illuminate\Support\Facades\DB;

/**
 * Fully reverses the wallet movements of a PAID, pre-weighbridge order when it is
 * cancelled, so the ledger stays balanced.
 *
 * A direct purchase (or a completed reservation) moves money in two legs:
 *     WO(−POO)  → WSSG(+POO)   buyer paid the platform
 *     WSSG(−PTS) → WS(+PTS)    platform paid the supplier
 *
 * This action reverses BOTH legs through WalletTransactionEngine using the amounts
 * snapshotted on the order (payment_of_owner / payment_to_supplier) — never
 * `final_amount`, and never a bare wallet increment that would credit the buyer
 * without clawing the money back from the supplier and system wallets (the previous
 * money-minting bug).
 *
 * Post-weighbridge orders are intentionally out of scope: they carry supplier /
 * consultant / VAT holds that must be unwound by the dedicated return-settlement
 * flow, so this action refuses to touch them rather than mis-refund.
 *
 * Pessimistic locking (lockForUpdate) prevents a double-refund race.
 */
final class RefundOrderToWalletAction
{
    public function __construct(
        private readonly WalletTransactionEngine $walletEngine,
    ) {}

    public function execute(Order $order): void
    {
        if ($order->payment_status !== Order::PAYMENT_PAID) {
            return; // nothing was charged → nothing to reverse
        }

        DB::transaction(function () use ($order): void {
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked || $locked->payment_status === Order::PAYMENT_REFUNDED) {
                return; // already refunded by a concurrent request
            }

            // Refunding a settled (post-weighbridge) order is not a simple reversal —
            // supplier/consultant/VAT holds are involved. Force the caller to use the
            // proper return/settlement flow instead of silently mis-refunding.
            if ($locked->{Order::WEIGHING_COMPLETED_AT} !== null) {
                throw new \RuntimeException(
                    "Order {$locked->id} is past the weighbridge; refund must go through the return/settlement flow."
                );
            }

            $locked->loadMissing('items');

            $buyerId = (int) $locked->placer_user_id;
            $systemUserId = (int) config('financial.system_user_id', 1);
            $supplierId = $locked->items->first()?->supplier_user_id;

            $poo = (float) $locked->{Order::PAYMENT_OF_OWNER};
            $pts = (float) $locked->{Order::PAYMENT_TO_SUPPLIER};

            // 1. Reverse the supplier leg first: WS(−PTS) → WSSG(+PTS).
            //    Done before the buyer refund so the system wallet holds the full POO
            //    again before paying the buyer back.
            if ($pts > 0 && $supplierId) {
                $this->walletEngine->process(
                    '-', $pts, FinancialCodes::WALLET_SUPPLIER, FinancialCodes::STATUS_AVAILABLE,
                    $locked, __('wallet.descriptions.refund_reverse_supplier', ['order_number' => $locked->order_number]),
                    (int) $supplierId,
                );
                $this->walletEngine->process(
                    '+', $pts, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_AVAILABLE,
                    $locked, __('wallet.descriptions.refund_reclaim_system', ['order_number' => $locked->order_number]),
                    $systemUserId,
                );
            }

            // 2. Reverse the buyer leg: WSSG(−POO) → WO(+POO).
            if ($poo > 0) {
                $this->walletEngine->process(
                    '-', $poo, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_AVAILABLE,
                    $locked, __('wallet.descriptions.refund_release_system', ['order_number' => $locked->order_number]),
                    $systemUserId,
                );
                $this->walletEngine->process(
                    '+', $poo, FinancialCodes::WALLET_OWNER, FinancialCodes::STATUS_AVAILABLE,
                    $locked, __('wallet.descriptions.refund_order', ['order_number' => $locked->order_number]),
                    $buyerId,
                );
            }

            $locked->update([Order::PAYMENT_STATUS => Order::PAYMENT_REFUNDED]);
        });
    }
}
