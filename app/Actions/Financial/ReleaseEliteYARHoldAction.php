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
 * Release the technical eliteYAR's commission hold after they submit their
 * Moadian (taxpayer-system) invoice — Doc Q in the purchase-process design.
 *
 * At weighbridge time (ProcessWeighbridgeResultAction), the eliteYAR's
 * payment is split and parked as:
 *   - eliteYARNet  → WEY balance_hold  (commission net of VAT)
 *   - eliteYARVat  → WVAT balance_hold (VAT portion, pending Moadian)
 *
 * When the eliteYAR issues Doc Q, both holds are released to available:
 *   WEY:  balance_hold(-) → balance(+)   (eliteYAR can now withdraw)
 *   WVAT: balance_hold(-) → balance(+)   (VAT recognised as due)
 *
 * The moadian_ref is recorded on the order so the release is idempotent:
 * a second call for the same order is a no-op.
 */
class ReleaseEliteYARHoldAction
{
    public function __construct(
        private readonly WalletTransactionEngine $walletEngine,
        private readonly InvoiceGenerator $invoiceGenerator,
        private readonly SettlementCalculatorService $calculator,
    ) {}

    /**
     * @param  Order  $order  The order whose eliteYAR hold should be released.
     * @param  string  $moadianRef  Doc-Q reference from the Moadian system.
     */
    public function execute(Order $order, string $moadianRef): void
    {
        // ── Idempotency guard ─────────────────────────────────────────────────
        if ($order->{Order::ELITE_YAR_MOADIAN_REF} !== null) {
            Log::channel('financial')->info("ReleaseEliteYARHoldAction skipped: order {$order->id} already has elite_yar_moadian_ref.");

            return;
        }

        // ── Pre-conditions ────────────────────────────────────────────────────
        if ($order->{Order::WEIGHING_COMPLETED_AT} === null) {
            throw new \RuntimeException(__('orders.errors.weighbridge_not_completed'));
        }

        $eliteYARGross = (float) $order->{Order::PAYMENT_TO_ELITE_YAR};
        if ($eliteYARGross <= 0) {
            // No eliteYAR commission was held for this order (no eliteYAR)
            Log::channel('financial')->info("ReleaseEliteYARHoldAction: order {$order->id} has no eliteYAR commission — only recording ref.");
            $order->forceFill([
                Order::ELITE_YAR_MOADIAN_REF      => $moadianRef,
                Order::ELITE_YAR_HOLD_RELEASED_AT => now(),
            ])->save();

            return;
        }

        $eliteYARUserId = $order->{Order::ELITE_YAR_USER_ID};
        if (! $eliteYARUserId) {
            throw new \RuntimeException(__('orders.errors.no_elite_yar'));
        }

        // ── Recalculate the net / VAT split ───────────────────────────────────
        // Must use the SAME bcmath split as ProcessWeighbridgeResultAction held, so
        // the released net+VAT exactly equals what was parked (no balance_hold dust).
        $vatRate = (float) ($order->{Order::VAT_RATE} ?? 10.0);
        ['net' => $eliteYARNet, 'vat' => $eliteYARVat] = $this->calculator->extractVat($eliteYARGross, $vatRate);

        DB::transaction(function () use (
            $order, $moadianRef,
            $eliteYARNet, $eliteYARVat,
            $eliteYARUserId
        ) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \RuntimeException(__('orders.internal_order_not_found'));
            }

            // Double-check idempotency inside the transaction
            if ($locked->{Order::ELITE_YAR_MOADIAN_REF} !== null) {
                return;
            }

            // WEY: release net from hold → available
            $this->walletEngine->debitEliteYAR(
                $locked, $eliteYARNet, FinancialCodes::STATUS_HOLD, $eliteYARUserId,
                "آزادسازی کمیسیون الیت یار (هولد) بابت سفارش {$locked->order_number}"
            );
            $this->walletEngine->creditEliteYAR(
                $locked, $eliteYARNet, FinancialCodes::STATUS_AVAILABLE, $eliteYARUserId,
                "کمیسیون الیت یار آزاد شد — فاکتور مودیان: {$moadianRef}"
            );

            // WVAT: release eliteYAR VAT from hold → available
            $this->walletEngine->debitVat(
                $locked, $eliteYARVat, FinancialCodes::STATUS_HOLD,
                "آزادسازی مالیات الیت یار (هولد) بابت سفارش {$locked->order_number}"
            );
            $this->walletEngine->creditVat(
                $locked, $eliteYARVat, FinancialCodes::STATUS_AVAILABLE,
                "مالیات الیت یار شناسایی شد — فاکتور مودیان: {$moadianRef}"
            );

            $locked->forceFill([
                Order::ELITE_YAR_MOADIAN_REF      => $moadianRef,
                Order::ELITE_YAR_HOLD_RELEASED_AT => now(),
            ])->save();

            // Doc Q — فاکتور مودیان الیت یار
            $this->invoiceGenerator->generateEliteYARMoadianInvoice($locked);

            Log::channel('financial')->info("EliteYAR hold released for order {$locked->id}. Ref: {$moadianRef}. Net: {$eliteYARNet}, VAT: {$eliteYARVat}");
        }, 3);
    }
}
