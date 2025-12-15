<?php

namespace App\Models\Concerns;

use App\Models\Order;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

trait HasOrderActions
{
    /**
     * بازگرداندن موجودی کالا به انبار (هنگام لغو سفارش)
     */
    public function restoreStock(): void
    {
        // استفاده از تراکنش برای اطمینان از بازگشت کامل موجودی‌ها
        DB::transaction(function () {
            foreach ($this->items as $item) {
                // محاسبه تعداد (اگر quantity_details نال بود آرایه خالی در نظر بگیر)
                $totalQty = array_sum($item->quantity_details ?? []);

                if ($totalQty > 0 && $item->productVariant) {
                    // افزایش موجودی انبار
                    $item->productVariant()->increment('stock_quantity', $totalQty);
                }
            }
        });
    }

    /**
     * عودت وجه به کیف پول کاربر (هنگام لغو سفارش)
     */
    public function refundToWallet(): void
    {
        // 1. بررسی شرط اولیه (آیا پولی پرداخت شده؟)
        // اگر وضعیت پرداخت شده نیست و مبلغ پرداختی هم صفر است، کاری نکن.
        if (
            $this->payment_status !== Order::PAYMENT_PAID &&
            $this->partial_payment_amount <= 0
        ) {
            return;
        }

        DB::transaction(function () {

            // قفل کردن رکورد سفارش برای جلوگیری از اجرای همزمان دو درخواست
            // این خط رکورد سفارش را رفرش می‌کند و تا پایان تراکنش قفل نگه می‌دارد
            $this->refresh();

            // دابل چک کردن وضعیت (شاید در میلی‌ثانیه قبل ریفاند شده باشد)
            if ($this->payment_status === Order::PAYMENT_REFUNDED) {
                return;
            }

            // محاسبه مبلغ بازگشتی
            $refundAmount = ($this->payment_status === Order::PAYMENT_PAID)
                ? $this->final_amount
                : $this->partial_payment_amount;

            $user = $this->placer;

            // دریافت کیف پول با قفل (Lock For Update)
            // این کار حیاتی است تا موجودی همزمان توسط پروسه دیگری تغییر نکند
            $wallet = $user->wallet()->lockForUpdate()->firstOrCreate([]);

            // 2. شارژ کیف پول
            $wallet->increment(Wallet::BALANCE, $refundAmount);

            // 3. ثبت تراکنش (استفاده از فایل زبان)
            $wallet->transactions()->create([
                WalletTransaction::AMOUNT => $refundAmount,
                WalletTransaction::TYPE   => WalletTransaction::TYPE_DEPOSIT,
                WalletTransaction::STATUS => WalletTransaction::STATUS_CONFIRMED,
                WalletTransaction::DESCRIPTION => __('wallet.descriptions.refund_order', ['order_number' => $this->order_number]),
                WalletTransaction::META => [
                    'order_id' => $this->id,
                    'reason'   => 'order_cancellation'
                ]
            ]);
            $this->update(['payment_status' => Order::PAYMENT_REFUNDED]);
        });
    }
}
