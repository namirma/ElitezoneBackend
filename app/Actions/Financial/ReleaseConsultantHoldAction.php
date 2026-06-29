<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Services\Financial\SettlementCalculatorService;
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
        private readonly SettlementCalculatorService $calculator,
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
            $order->forceFill([
                Order::CONSULTANT_MOADIAN_REF      => $moadianRef,
                Order::CONSULTANT_HOLD_RELEASED_AT => now(),
            ])->save();

            return;
        }

        $consultantUserId = $order->{Order::CONSULTANT_USER_ID};
        if (! $consultantUserId) {
            throw new \RuntimeException(__('orders.errors.no_consultant'));
        }

        // ── Recalculate the net / VAT split ───────────────────────────────────
        // Must use the SAME bcmath split as ProcessWeighbridgeResultAction held, so
        // the released net+VAT exactly equals what was parked (no balance_hold dust).
        $vatRate = (float) ($order->{Order::VAT_RATE} ?? 10.0);
        ['net' => $consultantNet, 'vat' => $consultantVat] = $this->calculator->extractVat($consultantGross, $vatRate);

        DB::transaction(function () use (
            $order, $moadianRef,
            $consultantNet, $consultantVat,
            $consultantUserId
        ) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \RuntimeException(__('orders.internal_order_not_found'));
            }

            // Double-check idempotency inside the transaction
            if ($locked->{Order::CONSULTANT_MOADIAN_REF} !== null) {
                return;
            }

            // WCT: release net from hold → available
            $this->walletEngine->debitConsultant(
                $locked, $consultantNet, FinancialCodes::STATUS_HOLD, $consultantUserId,
                "آزادسازی کمیسیون مشاور (هولد) بابت سفارش {$locked->order_number}"
            );
            $this->walletEngine->creditConsultant(
                $locked, $consultantNet, FinancialCodes::STATUS_AVAILABLE, $consultantUserId,
                "کمیسیون مشاور آزاد شد — فاکتور مودیان: {$moadianRef}"
            );

            // WVAT: release consultant VAT from hold → available
            $this->walletEngine->debitVat(
                $locked, $consultantVat, FinancialCodes::STATUS_HOLD,
                "آزادسازی مالیات مشاور (هولد) بابت سفارش {$locked->order_number}"
            );
            $this->walletEngine->creditVat(
                $locked, $consultantVat, FinancialCodes::STATUS_AVAILABLE,
                "مالیات مشاور شناسایی شد — فاکتور مودیان: {$moadianRef}"
            );

            $locked->forceFill([
                Order::CONSULTANT_MOADIAN_REF      => $moadianRef,
                Order::CONSULTANT_HOLD_RELEASED_AT => now(),
            ])->save();

            // Doc Q — فاکتور مودیان مشاور فنی
            $this->invoiceGenerator->generateConsultantMoadianInvoice($locked);

            Log::channel('financial')->info("Consultant hold released for order {$locked->id}. Ref: {$moadianRef}. Net: {$consultantNet}, VAT: {$consultantVat}");
        }, 3);
    }
}
