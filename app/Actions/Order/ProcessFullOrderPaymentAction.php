<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Actions\Financial\ProcessReservationCompletionAction;
use App\Constants\FinancialCodes;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Order;
use App\Models\Wallet;

class ProcessFullOrderPaymentAction
{
    public function __construct(
        private readonly ProcessReservationCompletionAction $completionAction,
    ) {}

    /**
     * تکمیلِ یک رزروِ موجود — تنها سناریوی باقی‌مانده.
     *
     * «خرید مستقیم» و «شروع رزرو» از اینجا رفتند: در مدل جدید هر دو سفارش را
     * خودشان می‌سازند و در `CheckoutController::pay/reserve` زندگی می‌کنند
     * (بند ۳.۳). این اکشن تنها مسیری است که با سفارشِ از-قبل-موجود کار دارد.
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

        // شاخه‌های «خرید مستقیم» و «رزرو» از اینجا رفتند: هر دو حالا سفارش را
        // خودشان **می‌سازند** و در `CheckoutController::pay/reserve` زندگی می‌کنند
        // (بند ۳.۳). این اکشن فقط تکمیلِ رزروِ موجود را انجام می‌دهد، که تنها
        // مسیری است که با یک سفارشِ از-قبل-موجود شروع می‌شود.
        throw new \RuntimeException(__('orders.cannot_pay_order'));
    }
}
