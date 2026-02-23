<?php

namespace App\Models\Concerns;

use App\Models\Order;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;

trait HasOrderActions
{
    /**
     * بازگرداندن موجودی کالا به انبار (هنگام لغو سفارش)
     */
    public function restoreStock(): void
    {
        // رفع باگ ۷: استفاده از سرویس یکپارچه انبار برای محاسبه دقیق بر اساس واحد و وزن
        app(InventoryService::class)->restoreStock($this);
    }

    /**
     * عودت وجه به کیف پول کاربر (هنگام لغو سفارش)
     */
    public function refundToWallet(): void
    {
        // 1. بررسی شرط اولیه (آیا پولی پرداخت شده؟)
        if (
            $this->payment_status !== Order::PAYMENT_PAID &&
            $this->partial_payment_amount <= 0
        ) {
            return;
        }

        DB::transaction(function () {

            // رفع باگ ۴: قفل کردن رکورد سفارش برای جلوگیری از اجرای همزمان دو درخواست (Race Condition)
            $lockedOrder = Order::where('id', $this->id)->lockForUpdate()->first();

            if (!$lockedOrder || $lockedOrder->payment_status === Order::PAYMENT_REFUNDED) {
                return;
            }

            // محاسبه مبلغ بازگشتی
            $refundAmount = ($lockedOrder->payment_status === Order::PAYMENT_PAID)
                ? $lockedOrder->final_amount
                : $lockedOrder->partial_payment_amount;

            $wallet = $lockedOrder->placer->wallet()->lockForUpdate()->firstOrCreate([]);

            // 2. شارژ کیف پول
            $wallet->increment(Wallet::BALANCE, $refundAmount);

            $wallet->transactions()->create([
                WalletTransaction::AMOUNT      => $refundAmount,
                WalletTransaction::TYPE        => WalletTransaction::TYPE_DEPOSIT,
                WalletTransaction::STATUS      => WalletTransaction::STATUS_CONFIRMED,
                WalletTransaction::DESCRIPTION => __('wallet.descriptions.refund_order', ['order_number' => $lockedOrder->order_number]),
                WalletTransaction::META        => [
                    'order_id' => $lockedOrder->id,
                    'reason'   => 'order_cancellation'
                ]
            ]);

            $lockedOrder->update(['payment_status' => Order::PAYMENT_REFUNDED]);
        });
    }
}
