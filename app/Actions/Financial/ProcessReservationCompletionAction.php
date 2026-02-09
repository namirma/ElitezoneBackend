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
use Exception;

class ProcessReservationCompletionAction
{
    public function __construct(
        protected WalletTransactionEngine $walletEngine,
        protected SettlementCalculatorService $calculator,
        protected InvoiceGenerator $invoiceGenerator
    ) {}

    /**
     * اجرای پروسه تکمیل وجه رزرو
     */
    public function execute(Order $order): void
    {
        Log::info("شروع فرآیند تکمیل وجه برای سفارش رزرو شده {$order->id}");

        DB::transaction(function () use ($order) {

            // 1. محاسبه مجدد و دقیق مبالغ (Snapshot)
            // نکته: اگر مقادیر رسمی وجود دارد استفاده می‌کنیم، وگرنه محاسبه می‌کنیم
            $op = (float) ($order->official_price ?: ($order->total_amount / max($order->weight_before_scale, 1)));
            $wbs = (float) ($order->weight_before_scale ?: 1);
            $trans = (float) ($order->shipping_cost ?: 0);

            // ضرایب (بهتر است این‌ها در دیتابیس سفارش ذخیره شده باشند)
            $ow = (float) ($order->owner_discount_rate ?: 0);
            $vat = (float) ($order->vat_rate ?: 10.0);
            $ssg = 3.0; // سهم سازه
            $co = 0.0;  // سهم مشاور

            // محاسبه POO (سهم نهایی خریدار) و PTS (سهم نهایی سوپلایر)
            $POO = $this->calculator->calculatePOO($trans, $op, $wbs, $ow, $vat);
            $PTS = $this->calculator->calculatePTS($trans, $op, $wbs, $ow, $ssg, $co, $vat);

            // مبلغی که قبلاً به عنوان بیعانه (رزرو) پرداخت شده
            $PTR = (float) $order->payment_to_reserve;

            // اگر POO معتبر نبود (مثلاً وزن صفر بوده)، از final_amount استفاده می‌کنیم
            if ($POO <= 0) {
                 $POO = (float) $order->final_amount;
                 // تخمین PTS (مثلاً ۹۰ درصد) اگر محاسبه شکست خورد
                 if ($PTS <= 0) $PTS = $POO * 0.9;
            }

            // 2. محاسبه مبلغ باقیمانده (Remaining Amount)
            $remainingAmount = $POO - $PTR;

            Log::info("Order {$order->id}: POO={$POO}, PTR={$PTR}, Remaining={$remainingAmount}");

            // 3. عملیات مالی: دریافت مابقی پول از خریدار
            if ($remainingAmount > 0) {
                // الف: کسر از حساب خریدار: -(Remaining, WO, A)
                $this->walletEngine->process('-', $remainingAmount, FinancialCodes::WALLET_OWNER, FinancialCodes::STATUS_AVAILABLE, $order);

                // ب: واریز به حساب آزاد شرکت: +(Remaining, WSSG, A)
                $this->walletEngine->process('+', $remainingAmount, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_AVAILABLE, $order);
            }

            // 4. عملیات مالی: آزادسازی پول بیعانه (تبدیل Hold به Available)
            // چون قبلاً PTR را با وضعیت Hold در WSSG ذخیره کردیم، الان باید آن را آزاد کنیم

            // الف: حذف از بلوکه شرکت: -(PTR, WSSG, H)
            $this->walletEngine->process('-', $PTR, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_HOLD, $order);

            // ب: اضافه به آزاد شرکت: +(PTR, WSSG, A)
            $this->walletEngine->process('+', $PTR, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_AVAILABLE, $order);

            // نکته: الان کل پول (POO) در حساب آزاد شرکت (WSSG) موجود است.

            // 5. تسویه با سوپلایر (خروج پول از شرکت به سوپلایر)

            // الف: برداشت سهم سوپلایر از شرکت: -(PTS, WSSG, A)
            $this->walletEngine->process('-', $PTS, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_AVAILABLE, $order);

            // ب: واریز به سوپلایر: +(PTS, WS, A)
            $this->walletEngine->process('+', $PTS, FinancialCodes::WALLET_SUPPLIER, FinancialCodes::STATUS_AVAILABLE, $order);

            // 6. بروزرسانی نهایی سفارش
            $order->update([
                Order::STATUS => Order::STATUS_PROCESSING, // وضعیت: در حال پردازش (آماده بارگیری)
                Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
                Order::PAYMENT_OF_OWNER => $POO,
                Order::PAYMENT_TO_SUPPLIER => $PTS,
                Order::PAID_AT => now(),
            ]);

            // 7. صدور اسناد نهایی (G, H, C)
            $this->invoiceGenerator->generate($order, FinancialCodes::DOC_FINAL_RECEIPT_OWNER);   // سند G
            $this->invoiceGenerator->generate($order, FinancialCodes::DOC_FINAL_REPORT_SUPPLIER); // سند H
            $this->invoiceGenerator->generate($order, FinancialCodes::DOC_LOADING_ORDER);         // سند C (مجوز بارگیری)

            Log::info("تکمیل وجه سفارش {$order->id} انجام شد و اسناد G, H, C صادر گردید.");
        });
    }
}
