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
 * Release the technical consultant's commission hold after they submit their
 * Moadian (taxpayer-system) invoice — Doc Q in the purchase-process design.
 *
 * At weighbridge time (ProcessWeighbridgeResultAction), the consultant's
 * payment is split and parked as:
 *   - consultantNet  → WCT balance_hold  (commission net of VAT)
 *   - consultantVat  → WVAT balance_hold (VAT portion, pending Moadian)
 *
 * When the consultant issues Doc Q, both holds are released to available:
 *   WCT:  balance_hold(-) → balance(+)   (consultant can now withdraw)
 *   WVAT: balance_hold(-) → balance(+)   (VAT recognised as due)
 *
 * The moadian_ref is recorded on the order so the release is idempotent:
 * a second call for the same order is a no-op.
 */
class ReleaseConsultantHoldAction
{
    public function __construct(
        private readonly WalletTransactionEngine $walletEngine,
        private readonly InvoiceGenerator $invoiceGenerator,
    ) {}

    /**
     * @param  Order  $order  The order whose consultant hold should be released.
     * @param  string  $moadianRef  Doc-Q reference from the Moadian system.
     */
    public function execute(Order $order, string $moadianRef): void
    {
        // ── Idempotency guard ─────────────────────────────────────────────────
        if ($order->{Order::CONSULTANT_MOADIAN_REF} !== null) {
            Log::channel('financial')->info("ReleaseConsultantHoldAction skipped: order {$order->id} already has consultant_moadian_ref.");

            return;
        }

        // ── Pre-conditions ────────────────────────────────────────────────────
        if ($order->{Order::WEIGHING_COMPLETED_AT} === null) {
            throw new \RuntimeException(__('orders.errors.weighbridge_not_completed'));
        }

        $consultantGross = (float) $order->{Order::PAYMENT_TO_CONSULTANT};
        if ($consultantGross <= 0) {
            // No consultant commission was held for this order (no consultant)
            Log::channel('financial')->info("ReleaseConsultantHoldAction: order {$order->id} has no consultant commission — only recording ref.");
            $order->update([
                Order::CONSULTANT_MOADIAN_REF => $moadianRef,
                Order::CONSULTANT_HOLD_RELEASED_AT => now(),
            ]);

            return;
        }

        $consultantUserId = $order->{Order::CONSULTANT_USER_ID};
        if (! $consultantUserId) {
            throw new \RuntimeException(__('orders.errors.no_consultant'));
        }

        // ── Recalculate the net / VAT split ───────────────────────────────────
        // At weighbridge time the split was:
        //   vatMultiplier = (vat% / 100) / (1 + vat% / 100)
        //   consultantVat = gross × vatMultiplier
        //   consultantNet = gross − consultantVat
        $vatRate = (float) ($order->{Order::VAT_RATE} ?? 10.0);
        $vatMultiplier = ($vatRate / 100.0) / (1.0 + ($vatRate / 100.0));
        $consultantVat = $consultantGross * $vatMultiplier;
        $consultantNet = $consultantGross - $consultantVat;

        $systemUserId = (int) config('financial.system_user_id', 1);
        $vatUserId = (int) config('financial.vat_user_id', $systemUserId);

        DB::transaction(function () use (
            $order, $moadianRef,
            $consultantNet, $consultantVat,
            $consultantUserId, $vatUserId
        ) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \RuntimeException("Order {$order->id} not found.");
            }

            // Double-check idempotency inside the transaction
            if ($locked->{Order::CONSULTANT_MOADIAN_REF} !== null) {
                return;
            }

            // WCT: release net from hold → available
            $this->walletEngine->process(
                '-', $consultantNet,
                FinancialCodes::WALLET_CONSULTANT, FinancialCodes::STATUS_HOLD,
                $locked,
                "آزادسازی کمیسیون مشاور (هولد) بابت سفارش {$locked->order_number}",
                $consultantUserId
            );
            $this->walletEngine->process(
                '+', $consultantNet,
                FinancialCodes::WALLET_CONSULTANT, FinancialCodes::STATUS_AVAILABLE,
                $locked,
                "کمیسیون مشاور آزاد شد — فاکتور مودیان: {$moadianRef}",
                $consultantUserId
            );

            // WVAT: release consultant VAT from hold → available
            $this->walletEngine->process(
                '-', $consultantVat,
                FinancialCodes::WALLET_VAT, FinancialCodes::STATUS_HOLD,
                $locked,
                "آزادسازی مالیات مشاور (هولد) بابت سفارش {$locked->order_number}",
                $vatUserId
            );
            $this->walletEngine->process(
                '+', $consultantVat,
                FinancialCodes::WALLET_VAT, FinancialCodes::STATUS_AVAILABLE,
                $locked,
                "مالیات مشاور شناسایی شد — فاکتور مودیان: {$moadianRef}",
                $vatUserId
            );

            $locked->update([
                Order::CONSULTANT_MOADIAN_REF => $moadianRef,
                Order::CONSULTANT_HOLD_RELEASED_AT => now(),
            ]);

            // Doc Q — فاکتور مودیان مشاور فنی (مشاور → سازه)
            $this->invoiceGenerator->generate($locked, FinancialCodes::DOC_TAX_INVOICE_CONSULTANT);

            Log::channel('financial')->info("Consultant hold released for order {$locked->id}. Ref: {$moadianRef}. Net: {$consultantNet}, VAT: {$consultantVat}");
        }, 3);
    }
}
