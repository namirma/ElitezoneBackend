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
 * Release the platform's (Saze) VAT hold after the admin issues Doc P — the
 * Moadian invoice from Saze to the buyer.
 *
 * At weighbridge time (ProcessWeighbridgeResultAction) the platform's net
 * margin VAT (sazeVat) is computed and parked in the WVAT wallet as a hold:
 *
 *   sazeGross = (POOM − PTSM) − eliteYARGross
 *   sazeVat   = sazeGross × (vat% / (100 + vat%))
 *   WVAT balance_hold(+)
 *
 * When the admin issues Doc P (Moadian invoice: Saze → buyer), this action
 * releases the hold to available — the VAT is now formally recognised as due:
 *
 *   WVAT balance_hold(−) → WVAT balance(+)
 *
 * Only callable after the weighbridge has been completed (WEIGHING_COMPLETED_AT ≠ null).
 * Idempotent: a second call for the same order returns early.
 */
class RecordSystemMoadianInvoiceAction
{
    public function __construct(
        private readonly WalletTransactionEngine $walletEngine,
        private readonly InvoiceGenerator $invoiceGenerator,
        private readonly \App\Services\Financial\SettlementCalculatorService $calculator,
    ) {}

    /**
     * @param  Order  $order  The order whose platform VAT hold should be released.
     * @param  string  $moadianRef  Doc-P reference from the Moadian system.
     *
     * @throws \RuntimeException when pre-conditions are not met.
     */
    public function execute(Order $order, string $moadianRef): void
    {
        // ── Idempotency guard ─────────────────────────────────────────────────
        if ($order->{Order::SYSTEM_MOADIAN_REF} !== null) {
            Log::channel('financial')->info("RecordSystemMoadianInvoiceAction skipped: order {$order->id} already has system_moadian_ref.");

            return;
        }

        // ── Pre-condition: weighbridge must be complete ────────────────────────
        if ($order->{Order::WEIGHING_COMPLETED_AT} === null) {
            throw new \RuntimeException(__('orders.errors.weighbridge_not_completed'));
        }

        // ── Derive sazeVat from the post-weighbridge snapshots ────────────────
        // ProcessWeighbridgeResultAction stores the updated values on the order:
        //   payment_of_owner      = POOM (updated from oldPOO)
        //   payment_to_supplier   = PTSM (updated from oldPTS)
        //   payment_to_elite_yar = eliteYARGross (0 when no eliteYAR)
        //   vat_rate              = the percentage used at payment time
        //
        // sazeGross = (POOM − PTSM) − eliteYARGross
        // sazeVat   = sazeGross × vatMultiplier
        $poom = (float) $order->payment_of_owner;
        $ptsm = (float) $order->payment_to_supplier;
        $eliteYARGross = (float) ($order->payment_to_elite_yar ?? 0);
        $vatRate = (float) ($order->{Order::VAT_RATE} ?? 10.0);

        // Same bcmath split used at weighbridge time — releases exactly what was held.
        $sazeGross = ($poom - $ptsm) - $eliteYARGross;
        $sazeVat = $this->calculator->extractVat($sazeGross, $vatRate)['vat'];

        DB::transaction(function () use (
            $order, $moadianRef,
            $sazeVat
        ) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \RuntimeException(__('orders.internal_order_not_found'));
            }

            // Double-check idempotency inside the transaction
            if ($locked->{Order::SYSTEM_MOADIAN_REF} !== null) {
                return;
            }

            if ($sazeVat > 0) {
                // Release platform VAT from WVAT hold → WVAT available
                $this->walletEngine->debitVat(
                    $locked, $sazeVat, FinancialCodes::STATUS_HOLD,
                    "آزادسازی مالیات پلتفرم (هولد) بابت سفارش {$locked->order_number}"
                );
                $this->walletEngine->creditVat(
                    $locked, $sazeVat, FinancialCodes::STATUS_AVAILABLE,
                    "مالیات پلتفرم شناسایی شد — فاکتور مودیان: {$moadianRef}"
                );
            }

            $locked->forceFill([
                Order::SYSTEM_MOADIAN_REF      => $moadianRef,
                Order::SYSTEM_HOLD_RELEASED_AT => now(),
            ])->save();

            // Doc P — فاکتور مودیان سیستم (سازه → خریدار)
            $this->invoiceGenerator->generateSystemMoadianInvoice($locked);

            Log::channel('financial')->info("System (Saze) VAT hold released for order {$locked->id}. Ref: {$moadianRef}. sazeVat: {$sazeVat}");
        }, 3);
    }
}
