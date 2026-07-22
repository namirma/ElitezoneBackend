<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Models\Order;
use Exception;
use Illuminate\Support\Facades\DB;

/**
 * Record the final-invoice reference on the order (locked + idempotent).
 *
 * IMPORTANT — this action deliberately does NOT hold any eliteYAR commission.
 * The eliteYAR's commission is the SAME money handled authoritatively by the
 * weighbridge flow:
 *   - weighbridge: WeighbridgeVatRouter parks the commission in WEY balance_hold
 *     (payment_to_elite_yar), computed from the ACTUAL delivered amounts.
 *   - Moadian: ReleaseEliteYARHoldAction releases WEY balance_hold → balance, so
 *     the eliteYAR can withdraw it.
 *
 * The previous fee-hold here used engineer_fee_amount/contractor_fee_amount — a
 * place-time ESTIMATE of that same commission — and did a bare `balance_hold++`
 * with no offsetting debit. Since finalize-invoice runs at PROCESSING (before the
 * weighbridge), keeping it would DOUBLE-hold (and, if ever released, double-pay)
 * the one commission, besides minting an unbalanced hold that was never released.
 * Per the product decision, the eliteYAR withdraws via the WEY → Moadian path, so
 * this action now only records the invoice reference.
 */
class FinalizeInvoiceAction
{
    public function execute(Order $order, string $finalInvoiceRef): void
    {
        DB::transaction(function () use ($order, $finalInvoiceRef) {
            // Lock + re-read under the lock so double-submit (double-click / retry) is a no-op.
            $locked = Order::where(Order::ID, $order->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new Exception(__('orders.elite_yar_not_found_internal'));
            }

            // Idempotent: already finalized → nothing to do.
            if (! empty($locked->{Order::FINAL_INVOICE_REF})) {
                return;
            }

            $locked->update([
                Order::FINAL_INVOICE_REF => $finalInvoiceRef,
            ]);

            // Keep the passed model in sync with the persisted state.
            $order->setAttribute(Order::FINAL_INVOICE_REF, $finalInvoiceRef);
        });
    }
}
