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
 * Release the technical eliteYar's commission hold after they submit their
 * Moadian (taxpayer-system) invoice — Doc Q in the purchase-process design.
 *
 * At weighbridge time (ProcessWeighbridgeResultAction), the eliteYar's
 * payment is split and parked as:
 *   - eliteYarNet  → WCT balance_hold  (commission net of VAT)
 *   - eliteYarVat  → WVAT balance_hold (VAT portion, pending Moadian)
 *
 * When the eliteYar issues Doc Q, both holds are released to available:
 *   WCT:  balance_hold(-) → balance(+)   (eliteYar can now withdraw)
 *   WVAT: balance_hold(-) → balance(+)   (VAT recognised as due)
 *
 * The moadian_ref is recorded on the order so the release is idempotent:
 * a second call for the same order is a no-op.
 */
class ReleaseEliteYarHoldAction
{
    public function __construct(
        private readonly WalletTransactionEngine $walletEngine,
        private readonly InvoiceGenerator $invoiceGenerator,
        private readonly SettlementCalculatorService $calculator,
    ) {}

    /**
     * @param  Order  $order  The order whose eliteYar hold should be released.
     * @param  string  $moadianRef  Doc-Q reference from the Moadian system.
     */
    public function execute(Order $order, string $moadianRef): void
    {
        // ── Idempotency guard ─────────────────────────────────────────────────
        if ($order->{Order::CONSULTANT_MOADIAN_REF} !== null) {
            Log::channel('financial')->info("ReleaseEliteYarHoldAction skipped: order {$order->id} already has consultant_moadian_ref.");

            return;
        }

        // ── Pre-conditions ────────────────────────────────────────────────────
        if ($order->{Order::WEIGHING_COMPLETED_AT} === null) {
            throw new \RuntimeException(__('orders.errors.weighbridge_not_completed'));
        }

        $eliteYarGross = (float) $order->{Order::PAYMENT_TO_CONSULTANT};
        if ($eliteYarGross <= 0) {
            // No eliteYar commission was held for this order (no eliteYar)
            Log::channel('financial')->info("ReleaseEliteYarHoldAction: order {$order->id} has no eliteYar commission — only recording ref.");
            $order->forceFill([
                Order::CONSULTANT_MOADIAN_REF      => $moadianRef,
                Order::CONSULTANT_HOLD_RELEASED_AT => now(),
            ])->save();

            return;
        }

        $eliteYarUserId = $order->{Order::CONSULTANT_USER_ID};
        if (! $eliteYarUserId) {
            throw new \RuntimeException(__('orders.errors.no_consultant'));
        }

        // ── Recalculate the net / VAT split ───────────────────────────────────
        // Must use the SAME bcmath split as ProcessWeighbridgeResultAction held, so
        // the released net+VAT exactly equals what was parked (no balance_hold dust).
        $vatRate = (float) ($order->{Order::VAT_RATE} ?? 10.0);
        ['net' => $eliteYarNet, 'vat' => $eliteYarVat] = $this->calculator->extractVat($eliteYarGross, $vatRate);

        DB::transaction(function () use (
            $order, $moadianRef,
            $eliteYarNet, $eliteYarVat,
            $eliteYarUserId
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
            $this->walletEngine->debitEliteYar(
                $locked, $eliteYarNet, FinancialCodes::STATUS_HOLD, $eliteYarUserId,
                "آزادسازی کمیسیون الیت یار (هولد) بابت سفارش {$locked->order_number}"
            );
            $this->walletEngine->creditEliteYar(
                $locked, $eliteYarNet, FinancialCodes::STATUS_AVAILABLE, $eliteYarUserId,
                "کمیسیون الیت یار آزاد شد — فاکتور مودیان: {$moadianRef}"
            );

            // WVAT: release eliteYar VAT from hold → available
            $this->walletEngine->debitVat(
                $locked, $eliteYarVat, FinancialCodes::STATUS_HOLD,
                "آزادسازی مالیات الیت یار (هولد) بابت سفارش {$locked->order_number}"
            );
            $this->walletEngine->creditVat(
                $locked, $eliteYarVat, FinancialCodes::STATUS_AVAILABLE,
                "مالیات الیت یار شناسایی شد — فاکتور مودیان: {$moadianRef}"
            );

            $locked->forceFill([
                Order::CONSULTANT_MOADIAN_REF      => $moadianRef,
                Order::CONSULTANT_HOLD_RELEASED_AT => now(),
            ])->save();

            // Doc Q — فاکتور مودیان الیت یار
            $this->invoiceGenerator->generateEliteYarMoadianInvoice($locked);

            Log::channel('financial')->info("EliteYar hold released for order {$locked->id}. Ref: {$moadianRef}. Net: {$eliteYarNet}, VAT: {$eliteYarVat}");
        }, 3);
    }
}
