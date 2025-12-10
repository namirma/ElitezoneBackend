<?php

namespace App\Models\Concerns;

use App\Models\Order;
use App\Models\Wallet;
use App\Models\WalletTransaction;

trait HasOrderActions
{
    /**
     * بازگرداندن موجودی کالا به انبار (هنگام لغو سفارش)
     */
    public function restoreStock(): void
    {
        foreach ($this->items as $item) {
            $totalQty = array_sum($item->quantity_details ?? []);

            if ($totalQty > 0) {
                // افزایش موجودی انبار
                $item->productVariant()->increment('stock_quantity', $totalQty);
            }
        }
    }

    /**
     * عودت وجه به کیف پول کاربر (هنگام لغو سفارش)
     */
    public function refundToWallet(): void
    {
        // فقط اگر پرداختی انجام شده باشد
        if ($this->payment_status === Order::PAYMENT_PAID || $this->partial_payment_amount > 0) {

            // محاسبه مبلغ بازگشتی (کل یا جزئی)
            $refundAmount = $this->payment_status === Order::PAYMENT_PAID
                ? $this->final_amount
                : $this->partial_payment_amount;

            $user = $this->placer;

            // دریافت یا ساخت کیف پول
            $wallet = $user->wallet()->firstOrCreate();

            // ۱. شارژ کیف پول
            $wallet->increment(Wallet::BALANCE, $refundAmount);

            $wallet->transactions()->create([
                'amount' => $refundAmount,
                'type'   => 'deposit',
                WalletTransaction::STATUS_CONFIRMED,
                'description' => "بازگشت وجه بابت لغو سفارش #{$this->order_number}",
                'order_id' => $this->id
            ]);

            $this->update(['payment_status' => Order::PAYMENT_REFUNDED]);
        }
    }
}
