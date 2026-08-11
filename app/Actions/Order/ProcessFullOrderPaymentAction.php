<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Actions\Financial\ProcessPreScalePaymentAction;
use App\Actions\Financial\ProcessReservationCompletionAction;
use App\Actions\Financial\ProcessReservationPaymentAction;
use App\Constants\FinancialCodes;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Order;
use App\Models\Wallet;

class ProcessFullOrderPaymentAction
{
    public function __construct(
        private readonly ProcessPreScalePaymentAction $purchaseAction,
        private readonly ProcessReservationPaymentAction $reservationAction,
        private readonly ProcessReservationCompletionAction $completionAction,
    ) {}

    /**
     * Route the order to the correct payment scenario and execute it.
     *
     * Scenario 1 — completion:      order is already reserved; pay the outstanding balance.
     * Scenario 2 — direct purchase: total < threshold, or user did not request reservation.
     * Scenario 3 — reservation:     total >= threshold and user explicitly requested reserve.
     *
     * @throws InsufficientBalanceException when the wallet cannot cover the required amount.
     */
    public function execute(Order $order, bool $isReserveRequested, Wallet $wallet): PayOrderResult
    {
        // ── Scenario 1: completion of a previously reserved order ──────────────
        if ($order->status === Order::STATUS_RESERVED) {
            // Balance guard lives inside completionAction — it uses the actual POO
            // formula, not the stale final_amount snapshot stored on the order row.
            $this->completionAction->execute($order);

            return new PayOrderResult(
                scenario: 'completion',
                documents: [
                    FinancialCodes::DOC_FINAL_RECEIPT_OWNER,
                    FinancialCodes::DOC_FINAL_REPORT_SUPPLIER,
                    FinancialCodes::DOC_LOADING_ORDER,
                ],
                messageKey: 'orders.completion_successful',
            );
        }

        // اندازهٔ سفارش پیش از تخفیف کوپن — تخفیف نباید گزینهٔ رزرو را حذف کند.
        $totalAmount = $order->reservationBasisAmount();

        // ── Scenario 2: direct purchase ─────────────────────────────────────────
        // `final_amount` only decides reservation-vs-direct (an order-size check).
        // The balance guard is delegated to ProcessPreScalePaymentAction: it recomputes
        // the authoritative payable amount (POO) inside its locked transaction and the
        // buyer debit throws InsufficientBalanceException(POO, balance) if the wallet
        // can't cover it. Guarding here against final_amount would compare the wrong
        // number (final_amount ≠ POO) and is the previous "two sources of truth" bug.
        if ($totalAmount < $this->threshold() || ! $isReserveRequested) {
            $this->purchaseAction->execute($order);

            return new PayOrderResult(
                scenario: 'direct_purchase',
                documents: [
                    FinancialCodes::DOC_RECEIPT_OWNER,
                    FinancialCodes::DOC_REPORT_SUPPLIER,
                    FinancialCodes::DOC_LOADING_ORDER,
                    FinancialCodes::DOC_ELITE_YAR_REPORT,
                ],
                messageKey: 'orders.full_payment_successful',
            );
        }

        // ── Scenario 3: reservation ─────────────────────────────────────────────
        $reserveAmount = $this->threshold();

        if ($wallet->balance < $reserveAmount) {
            throw new InsufficientBalanceException($reserveAmount, $wallet->balance);
        }

        $this->reservationAction->execute(
            $order,
            $reserveAmount,
            validityHours: (int) config('financial.reservation_validity_hours', 2),
        );

        return new PayOrderResult(
            scenario: 'reservation',
            documents: [
                FinancialCodes::DOC_RESERVE_RECEIPT,
                FinancialCodes::DOC_RESERVE_REPORT,
            ],
            messageKey: 'orders.reservation_successful',
        );
    }

    private function threshold(): float
    {
        return (float) config('financial.reservation_threshold', 2_000_000_000);
    }
}
