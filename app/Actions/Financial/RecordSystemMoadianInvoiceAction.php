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
 *   sazeGross = (POOM − PTSM) − consultantGross
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
    ) {}

    /**
     * @param  Order  $order      The order whose platform VAT hold should be released.
     * @param  string $moadianRef Doc-P reference from the Moadian system.
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
        //   payment_to_consultant = consultantGross (0 when no consultant)
        //   vat_rate              = the percentage used at payment time
        //
        // sazeGross = (POOM − PTSM) − consultantGross
        // sazeVat   = sazeGross × vatMultiplier
        $poom           = (float) $order->payment_of_owner;
        $ptsm           = (float) $order->payment_to_supplier;
        $consultantGross = (float) ($order->payment_to_consultant ?? 0);
        $vatRate        = (float) ($order->{Order::VAT_RATE} ?? 10.0);
        $vatMultiplier  = ($vatRate / 100.0) / (1.0 + ($vatRate / 100.0));

        $sazeGross = ($poom - $ptsm) - $consultantGross;
        $sazeVat   = $sazeGross * $vatMultiplier;

        $systemUserId = (int) config('financial.system_user_id', 1);
        $vatUserId    = (int) config('financial.vat_user_id', $systemUserId);

        DB::transaction(function () use (
            $order, $moadianRef,
            $sazeVat, $systemUserId, $vatUserId
        ) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \RuntimeException("Order {$order->id} not found.");
            }

            // Double-check idempotency inside the transaction
            if ($locked->{Order::SYSTEM_MOADIAN_REF} !== null) {
                return;
            }

            if ($sazeVat > 0) {
                // Release platform VAT from WVAT hold → WVAT available
                $this->walletEngine->process(
                    '-', $sazeVat,
                    FinancialCodes::WALLET_VAT, FinancialCodes::STATUS_HOLD,
                    $locked,
                    "آزادسازی مالیات پلتفرم (هولد) بابت سفارش {$locked->order_number}",
                    $vatUserId
                );
                $this->walletEngine->process(
                    '+', $sazeVat,
                    FinancialCodes::WALLET_VAT, FinancialCodes::STATUS_AVAILABLE,
                    $locked,
                    "مالیات پلتفرم شناسایی شد — فاکتور مودیان: {$moadianRef}",
                    $vatUserId
                );
            }

            $locked->update([
                Order::SYSTEM_MOADIAN_REF      => $moadianRef,
                Order::SYSTEM_HOLD_RELEASED_AT => now(),
            ]);

            // ── Doc P: فاکتور مودیان سیستم (سازه → خریدار) ───────────────
            $this->invoiceGenerator->generate($locked, FinancialCodes::DOC_TAX_INVOICE_BUYER);

            Log::channel('financial')->info("System (Saze) VAT hold released for order {$locked->id}. Ref: {$moadianRef}. sazeVat: {$sazeVat}");
        }, 3);
    }
}
