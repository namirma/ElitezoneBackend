<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Services\Financial\WalletTransactionEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessReservationPaymentAction
{
    public function __construct(
        protected WalletTransactionEngine $walletEngine
    ) {}

    public function execute(Order $order, float $reserveAmount, int $validityHours = 2): void
    {
        if ($this->isAlreadyProcessed($order)) {
            Log::warning("ProcessReservationPaymentAction skipped: order {$order->id} already reserved.");
            return;
        }

        if ($reserveAmount <= 0) {
            throw new \InvalidArgumentException('مبلغ رزرو باید بزرگ‌تر از صفر باشد.');
        }

        DB::transaction(function () use ($order, $reserveAmount, $validityHours) {

            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (!$locked) {
                throw new \RuntimeException("Order {$order->id} not found.");
            }

            if ($this->isAlreadyProcessed($locked)) {
                Log::warning("Race detected: order {$locked->id} already reserved.");
                return;
            }

            $buyerId = $locked->placer_user_id;
            $systemUserId = 1;
            $systemWalletCode = FinancialCodes::WALLET_SAZE;

            $this->walletEngine->process('-', $reserveAmount, FinancialCodes::WALLET_OWNER, FinancialCodes::STATUS_AVAILABLE, $locked, "پرداخت مبلغ بیعانه برای رزرو سفارش {$locked->order_number}", $buyerId);

            $this->walletEngine->process('+', $reserveAmount, $systemWalletCode, FinancialCodes::STATUS_HOLD, $locked, "امانت مبلغ رزرو سفارش {$locked->order_number}", $systemUserId);

            $locked->update([
                'status'                  => Order::STATUS_RESERVED,
                Order::PAYMENT_TO_RESERVE => $reserveAmount,
                'reservation_expires_at'  => now()->addHours($validityHours),
            ]);

            Log::info("سفارش {$locked->order_number} رزرو شد.");
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
