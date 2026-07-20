<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Services\Financial\WalletTransactionEngine;
use App\Services\Invoice\InvoiceGenerator;
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
        private readonly WalletTransactionEngine $walletEngine,
        private readonly InvoiceGenerator $invoiceGenerator,
        private readonly \App\Services\Financial\SettlementCalculatorService $calculator,
    ) {}

    /**
     * @param  Order  $order  The order whose supplier VAT hold should be released.
     * @param  string  $moadianRef  Doc-O reference from the Moadian system.
     */
    public function execute(Order $order, string $moadianRef): void
    {
        // ── Idempotency guard ─────────────────────────────────────────────────
        if ($order->{Order::SUPPLIER_MOADIAN_REF} !== null) {
            Log::channel('financial')->info("RecordSupplierMoadianInvoiceAction skipped: order {$order->id} already has supplier_moadian_ref.");

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
        // Same bcmath split used at weighbridge time — releases exactly what was held.
        $ptsm = (float) $order->{Order::PAYMENT_TO_SUPPLIER};
        $vatRate = (float) ($order->{Order::VAT_RATE} ?? 10.0);
        $supplierVat = $this->calculator->extractVat($ptsm, $vatRate)['vat'];

        DB::transaction(function () use (
            $order, $moadianRef,
            $supplierVat
        ) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \RuntimeException(__('orders.internal_order_not_found'));
            }

            // Double-check idempotency inside the transaction
            if ($locked->{Order::SUPPLIER_MOADIAN_REF} !== null) {
                return;
            }

            if ($supplierVat > 0) {
                // Release supplier VAT from WVAT hold → WVAT available
                $this->walletEngine->debitVat(
                    $locked, $supplierVat, FinancialCodes::STATUS_HOLD,
                    "آزادسازی مالیات سوپلایر (هولد) بابت سفارش {$locked->order_number}"
                );
                $this->walletEngine->creditVat(
                    $locked, $supplierVat, FinancialCodes::STATUS_AVAILABLE,
                    "مالیات سوپلایر شناسایی شد — فاکتور مودیان: {$moadianRef}"
                );
            }

            $locked->forceFill([
                Order::SUPPLIER_MOADIAN_REF => $moadianRef,
                Order::SUPPLIER_HOLD_RELEASED_AT => now(),
            ])->save();

            // Doc O — فاکتور مودیان سوپلایر
            $this->invoiceGenerator->generateSupplierMoadianInvoice($locked);

            Log::channel('financial')->info("Supplier VAT hold released for order {$locked->id}. Ref: {$moadianRef}. VAT: {$supplierVat}");
        }, 3);
    }
}
