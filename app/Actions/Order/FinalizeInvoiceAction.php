<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FinalizeInvoiceAction
{
    /**
     * Record the final invoice reference and hold the eliteYAR's fee in their wallet.
     *
     * Wraps both writes in a single DB::transaction so the order update and
     * the wallet hold are always atomic.
     *
     * @throws Exception when the eliteYAR user cannot be found
     */
    public function execute(Order $order, string $finalInvoiceRef): void
    {
        [$feeAmount, $eliteYARUserId] = $this->resolveFee($order);

        DB::transaction(function () use ($order, $finalInvoiceRef, $feeAmount, $eliteYARUserId) {
            $order->update([
                Order::FINAL_INVOICE_REF => $finalInvoiceRef,
            ]);

            if ($feeAmount && $eliteYARUserId) {
                $eliteYAR = User::find($eliteYARUserId);

                if (! $eliteYAR) {
                    Log::channel('orders')->error("User not found for eliteYAR fee hold. Order: {$order->id}");
                    throw new Exception(__('orders.consultant_not_found_internal'));
                }

                $wallet = $eliteYAR->wallet ?: $eliteYAR->wallet()->create([]);

                $wallet->transactions()->create([
                    'order_id'    => $order->id,
                    'amount'      => $feeAmount,
                    'type'        => WalletTransaction::TYPE_FEE_HOLD,
                    'status'      => WalletTransaction::STATUS_PENDING,
                    'description' => __('orders.fee_hold_description', ['order_number' => $order->order_number]),
                ]);

                $wallet->increment('balance_hold', $feeAmount);
            }
        });
    }

    /**
     * Determine the eliteYAR fee amount and recipient from the order.
     *
     * @return array{0: int|float|null, 1: int|null}  [feeAmount, eliteYARUserId]
     */
    private function resolveFee(Order $order): array
    {
        if ($order->engineer_fee_amount > 0) {
            return [$order->engineer_fee_amount, $order->beneficiary_user_id];
        }

        if ($order->contractor_fee_amount > 0) {
            return [$order->contractor_fee_amount, $order->beneficiary_user_id];
        }

        return [null, null];
    }
}
