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

            // 1. محاسبه مجدد و دقیق مبالغ با استفاده از سرویس فرمول‌ها
            $op = (float) ($order->official_price ?: ($order->items->first()->unit_price ?? 0));
            $wbs = (float) ($order->weight_before_scale ?: 1);
            $trans = (float) ($order->shipping_cost ?: 0);
            $vat = (float) ($order->vat_rate ?: 10.0);

            // استفاده از سرویس کالیبره شده برای دریافت ضرایب صحیح (جلوگیری از هاردکد کردن)
            $badgeCode = $order->beneficiary?->badge?->code ?? 'pioneer';
            $rates = $this->calculator->getCommissionRates($badgeCode);

            $ow  = $rates['ow_final_rate'];
            $ssg = $rates['ssg_final_rate'];
            $co  = $rates['co_final_rate'];

            // محاسبه POO و PTS نهایی
            $POO = $this->calculator->calculatePOO($trans, $op, $wbs, $ow, $vat);
            $PTS = $this->calculator->calculatePTS($trans, $op, $wbs, $ow, $ssg, $co, $vat);

            // مبلغی که قبلاً به عنوان بیعانه (رزرو) پرداخت شده
            $PTR = (float) $order->payment_to_reserve;

            if ($POO <= 0) {
                $POO = (float) $order->final_amount;
                if ($PTS <= 0) $PTS = $POO * 0.9;
            }

            // 2. محاسبه مبلغ باقیمانده (Remaining Amount)
            $remainingAmount = $POO - $PTR;

            // استخراج شناسه‌های مورد نیاز برای تراکنش‌ها
            $buyerId = $order->placer_user_id;
            $realSupplierId = $order->items->first()->supplier_user_id;
            $systemUserId = 1;
            $systemWalletCode = FinancialCodes::WALLET_SAZE;

            Log::info("Order {$order->id}: POO={$POO}, PTR={$PTR}, Remaining={$remainingAmount}");

            // 3. عملیات مالی: دریافت مابقی پول از خریدار (یا بازگشت افت قیمت)
            if ($remainingAmount > 0) {
                // دریافت مابه‌التفاوت از خریدار به سازه
                $this->walletEngine->process('-', $remainingAmount, FinancialCodes::WALLET_OWNER, FinancialCodes::STATUS_AVAILABLE, $order, "تکمیل وجه سفارش {$order->order_number}", $buyerId);
                $this->walletEngine->process('+', $remainingAmount, $systemWalletCode, FinancialCodes::STATUS_AVAILABLE, $order, "دریافت وجه تکمیلی سفارش {$order->order_number}", $systemUserId);
            } elseif ($remainingAmount < 0) {
                // اگر قیمت افت کرده باشد، مابه‌التفاوت را به خریدار برمی‌گردانیم
                $refundAmount = abs($remainingAmount);
                $this->walletEngine->process('-', $refundAmount, $systemWalletCode, FinancialCodes::STATUS_AVAILABLE, $order, "بازگشت مابه‌التفاوت افت قیمت سفارش {$order->order_number}", $systemUserId);
                $this->walletEngine->process('+', $refundAmount, FinancialCodes::WALLET_OWNER, FinancialCodes::STATUS_AVAILABLE, $order, "دریافت مابه‌التفاوت افت قیمت رزرو", $buyerId);
            }

            // 4. عملیات مالی: آزادسازی پول بیعانه (تبدیل Hold به Available در سازه)
            $this->walletEngine->process('-', $PTR, $systemWalletCode, FinancialCodes::STATUS_HOLD, $order, "خروج از حالت هولد مبلغ رزرو سفارش {$order->order_number}", $systemUserId);
            $this->walletEngine->process('+', $PTR, $systemWalletCode, FinancialCodes::STATUS_AVAILABLE, $order, "تبدیل موجودی هولد رزرو به قطعی", $systemUserId);

            // 5. تسویه با سوپلایر (خروج سهم سوپلایر از سازه)
            $this->walletEngine->process('-', $PTS, $systemWalletCode, FinancialCodes::STATUS_AVAILABLE, $order, "تخصیص سهم تامین‌کننده سفارش {$order->order_number}", $systemUserId);
            $this->walletEngine->process('+', $PTS, FinancialCodes::WALLET_SUPPLIER, FinancialCodes::STATUS_AVAILABLE, $order, "واریز سهم فروش سفارش {$order->order_number}", $realSupplierId);

            // 6. بروزرسانی نهایی سفارش
            $order->update([
                Order::STATUS => Order::STATUS_PROCESSING, 
                Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
                Order::PAYMENT_OF_OWNER => $POO,
                Order::PAYMENT_TO_SUPPLIER => $PTS,
                Order::PAID_AT => now(),
            ]);

            // 7. صدور اسناد نهایی (G, H, C)
            $this->invoiceGenerator->generate($order, FinancialCodes::DOC_FINAL_RECEIPT_OWNER);
            $this->invoiceGenerator->generate($order, FinancialCodes::DOC_FINAL_REPORT_SUPPLIER);
            $this->invoiceGenerator->generate($order, FinancialCodes::DOC_LOADING_ORDER);

            Log::info("تکمیل وجه سفارش {$order->id} انجام شد و اسناد G, H, C صادر گردید.");
        });
    }
}
