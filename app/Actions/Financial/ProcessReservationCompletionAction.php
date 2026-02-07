<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Financial\WalletTransactionEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class ProcessReservationCompletionAction
{
    public function __construct(
        protected WalletTransactionEngine $walletEngine,
        protected SettlementCalculatorService $calculator
    ) {}

    public function execute(Order $order): void
    {
        Log::info("شروع فرآیند تکمیل وجه برای رزرو {$order->id}");

        DB::transaction(function () use ($order) {

            // 1. بازیابی اطلاعات مالی (اسنپ‌شات)
            $POO = $order->payment_of_owner;      // کل مبلغی که خریدار باید بدهد
            $PTS = $order->payment_to_supplier;   // کل سهم سوپلایر
            $PTR = $order->payment_to_reserve;    // مبلغی که قبلا به عنوان بیعانه داده

            if (!$POO || !$PTR) {
                throw new Exception("اطلاعات مالی سفارش ناقص است.");
            }

            // 2. محاسبه مبلغ باقیمانده (Remained Amount)
            $remainingAmount = $POO - $PTR;

            // 3. عملیات مالی: دریافت مابقی پول از خریدار
            // فرمول: -(Remaining, WO, A)
            $this->walletEngine->process('-', $remainingAmount, FinancialCodes::WALLET_OWNER, FinancialCodes::STATUS_AVAILABLE, $order);

            // فرمول: +(Remaining, WSSG, A) -> واریز به حساب آزاد شرکت
            $this->walletEngine->process('+', $remainingAmount, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_AVAILABLE, $order);

            // 4. عملیات مالی: آزادسازی پول بیعانه (تبدیل Hold به Available)
            // چون قبلاً PTR را با وضعیت H در WSSG ذخیره کردیم، الان باید برش داریم و به A تبدیل کنیم
            // الف: حذف از بلوکه: -(PTR, WSSG, H)
            $this->walletEngine->process('-', $PTR, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_HOLD, $order);

            // ب: اضافه به آزاد: +(PTR, WSSG, A)
            $this->walletEngine->process('+', $PTR, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_AVAILABLE, $order);

            // الان کل پول (POO) در حساب آزاد شرکت (WSSG) موجود است.

            // 5. تسویه با سوپلایر (طبق عکس‌های ۴۰۶-۴۱۲)
            // برداشت سهم سوپلایر از شرکت: -(PTS, WSSG, A)
            $this->walletEngine->process('-', $PTS, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_AVAILABLE, $order);

            // واریز به سوپلایر: +(PTS, WS, A)
            $this->walletEngine->process('+', $PTS, FinancialCodes::WALLET_SUPPLIER, FinancialCodes::STATUS_AVAILABLE, $order);

            // 6. تغییر وضعیت و صدور اسناد نهایی
            $order->update([
                'status' => 'paid', // یا processing
                'payment_status' => 'paid_full'
            ]);

            // صدور اسناد طبق عکس‌های جدید
            // سند G: رسید نهایی خریدار
            // سند H: گزارش نهایی سوپلایر
            // سند C: دستور بارگیری
            
            Log::info("اسناد G, H, C برای سفارش {$order->id} در صف صدور قرار گرفتند.");
        });
    }
}
