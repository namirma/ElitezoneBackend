<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Services\Financial\WalletTransactionEngine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessReservationPaymentAction
{
    public function __construct(
        protected WalletTransactionEngine $walletEngine
    ) {}

    /**
     * اجرای فلوچارت پرداخت رزرو (PTR)
     * @param Order $order
     * @param float $ptrAmount مبلغ رزرو
     * @param int $validityHours مدت اعتبار
     */
    public function execute(Order $order, float $reserveAmount, int $validityHours = 2): void
    {
        DB::transaction(function () use ($order, $reserveAmount, $validityHours) {

            $buyerId = $order->placer_user_id;
            $systemUserId = 1;
            $systemWalletCode = FinancialCodes::WALLET_SAZE;

            $this->walletEngine->process('-', $reserveAmount, FinancialCodes::WALLET_OWNER, FinancialCodes::STATUS_AVAILABLE, $order, "پرداخت مبلغ بیعانه برای رزرو سفارش {$order->order_number}", $buyerId);

            $this->walletEngine->process('+', $reserveAmount, $systemWalletCode, FinancialCodes::STATUS_HOLD, $order, "امانت (هولد) مبلغ رزرو سفارش {$order->order_number}", $systemUserId);

            $order->update([
                'status'                 => Order::STATUS_RESERVED,
                Order::PAYMENT_TO_RESERVE => $reserveAmount,
                'reservation_expires_at' => now()->addHours($validityHours),
            ]);

            Log::info("سفارش {$order->order_number} رزرو شد و مبلغ {$reserveAmount} در کیف پول سازه هولد گردید.");
        });
    }
}
