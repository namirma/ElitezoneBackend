<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Services\Financial\WalletTransactionEngine;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessReservationPaymentAction
{
    public function __construct(
        protected WalletTransactionEngine $walletEngine,
        protected InvoiceGenerator $invoiceGenerator,
    ) {}

    public function execute(Order $order, float $reserveAmount, int $validityHours = 2): void
    {
        if ($this->isAlreadyProcessed($order)) {
            Log::channel('financial')->warning("ProcessReservationPaymentAction skipped: order {$order->id} already reserved.");

            return;
        }

        if ($reserveAmount <= 0) {
            throw new \InvalidArgumentException('مبلغ رزرو باید بزرگ‌تر از صفر باشد.');
        }

        DB::transaction(function () use ($order, $reserveAmount, $validityHours) {

            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \RuntimeException(__('orders.internal_order_not_found'));
            }

            if ($this->isAlreadyProcessed($locked)) {
                Log::channel('financial')->warning("Race detected: order {$locked->id} already reserved.");

                return;
            }

            $this->walletEngine->debitBuyer($locked, $reserveAmount, FinancialCodes::STATUS_AVAILABLE, "پرداخت مبلغ بیعانه برای رزرو سفارش {$locked->order_number}");

            $this->walletEngine->creditSystem($locked, $reserveAmount, FinancialCodes::STATUS_HOLD, "امانت مبلغ رزرو سفارش {$locked->order_number}");

            $locked->forceFill([
                Order::STATUS => Order::STATUS_RESERVED,
                Order::PAYMENT_TO_RESERVE => $reserveAmount,
                Order::RESERVATION_EXPIRES_AT => now()->addHours($validityHours),
            ])->save();

            // ── صدور اسناد مالی رزرو (E + F) ────────────────────────────
            $this->invoiceGenerator->generateReservationDocs($locked);

            Log::channel('financial')->info("سفارش {$locked->order_number} رزرو شد.");
        }, 3);
    }

    private function isAlreadyProcessed(Order $order): bool
    {
        return in_array(
            $order->status,
            [Order::STATUS_RESERVED, Order::STATUS_RESERVED_EXTENDED],
            true
        );
    }
}
