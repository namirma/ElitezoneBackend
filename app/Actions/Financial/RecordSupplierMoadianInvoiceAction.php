<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Services\Financial\WalletTransactionEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Release the supplier's VAT hold after they issue their Moadian invoice — Doc O.
 *
 * At weighbridge time (ProcessWeighbridgeResultAction) the supplier's VAT is
 * computed from PTSM and parked in the WVAT wallet as a hold:
 *   supplierVat = PTSM × (VAT% / (1 + VAT%))
 *   WVAT balance_hold(+)
 *
 * When the supplier issues Doc O (Moadian invoice: supplier → Saze), the hold
 * is released to available — the VAT is now formally recognised as due:
 *   WVAT balance_hold(-) → WVAT balance(+)
 *
 * Idempotent: a second call for the same order is a no-op.
 */
class RecordSupplierMoadianInvoiceAction
{
    public function __construct(
        private readonly WalletTransactionEngine $walletEngine
    ) {}

    /**
     * @param  Order  $order      The order whose supplier VAT hold should be released.
     * @param  string $moadianRef Doc-O reference from the Moadian system.
     */
    public function execute(Order $order, string $moadianRef): void
    {
        // ── Idempotency guard ─────────────────────────────────────────────────
        if ($order->{Order::SUPPLIER_MOADIAN_REF} !== null) {
            Log::info("RecordSupplierMoadianInvoiceAction skipped: order {$order->id} already has supplier_moadian_ref.");

            return;
        }

        // ── Pre-conditions ────────────────────────────────────────────────────
        if ($order->{Order::WEIGHING_COMPLETED_AT} === null) {
            throw new \RuntimeException(__('orders.errors.weighbridge_not_completed'));
        }

        // ── Derive the supplier VAT from the post-weighbridge PTSM ────────────
        // PTSM is stored as payment_to_supplier after the weighbridge action
        // updates it. The VAT portion embedded in PTSM is:
        //   supplierVat = PTSM × (vat% / 100) / (1 + vat% / 100)
        $ptsm          = (float) $order->{Order::PAYMENT_TO_SUPPLIER};
        $vatRate        = (float) ($order->{Order::VAT_RATE} ?? 10.0);
        $vatMultiplier  = ($vatRate / 100.0) / (1.0 + ($vatRate / 100.0));
        $supplierVat    = $ptsm * $vatMultiplier;

        $realSupplierId = $this->resolveSupplierUserId($order);
        $systemUserId   = (int) config('financial.system_user_id', 1);
        $vatUserId      = (int) config('financial.vat_user_id', $systemUserId);

        DB::transaction(function () use (
            $order, $moadianRef,
            $supplierVat, $realSupplierId, $vatUserId
        ) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \RuntimeException("Order {$order->id} not found.");
            }

            // Double-check idempotency inside the transaction
            if ($locked->{Order::SUPPLIER_MOADIAN_REF} !== null) {
                return;
            }

            if ($supplierVat > 0) {
                // Release supplier VAT from WVAT hold → WVAT available
                $this->walletEngine->process(
                    '-', $supplierVat,
                    FinancialCodes::WALLET_VAT, FinancialCodes::STATUS_HOLD,
                    $locked,
                    "آزادسازی مالیات سوپلایر (هولد) بابت سفارش {$locked->order_number}",
                    $vatUserId
                );
                $this->walletEngine->process(
                    '+', $supplierVat,
                    FinancialCodes::WALLET_VAT, FinancialCodes::STATUS_AVAILABLE,
                    $locked,
                    "مالیات سوپلایر شناسایی شد — فاکتور مودیان: {$moadianRef}",
                    $vatUserId
                );
            }

            $locked->update([
                Order::SUPPLIER_MOADIAN_REF      => $moadianRef,
                Order::SUPPLIER_HOLD_RELEASED_AT => now(),
            ]);

            Log::info("Supplier VAT hold released for order {$locked->id}. Ref: {$moadianRef}. VAT: {$supplierVat}");
        }, 3);
    }

    private function resolveSupplierUserId(Order $order): ?int
    {
        return $order->items()->value('supplier_user_id');
    }
}
