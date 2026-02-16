<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Financial\WalletTransactionEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessPreScalePaymentAction
{
    public function __construct(
        protected SettlementCalculatorService $calculator,
        protected WalletTransactionEngine $walletEngine
    ) {}

    /**
     * اجرای فرآیند پرداخت اولیه (قبل از باسکول)
     * شامل: محاسبه سهم‌ها، کسر از کیف پول، و آپدیت وضعیت سفارش
     */
    public function execute(Order $order): void
    {
        DB::transaction(function () use ($order) {

            // --- گام ۱: محاسبه دقیق سهم‌ها (Snapshot Calculation) ---

            // بازیابی قیمت واحد (با هندل کردن حالت Null)
            $officialPrice = (float) $order->getAttribute(Order::OFFICIAL_PRICE);
            if ($officialPrice <= 0 && $order->items->isNotEmpty()) {
                $officialPrice = (float) $order->items->first()->unit_price;
            }

            // بازیابی مقادیر محاسباتی
            $weightBeforeScale = (float) ($order->getAttribute(Order::WEIGHT_BEFORE_SCALE) ?? 0);
            $shippingCost      = (float) ($order->shipping_cost ?? 0);
            $vatRate           = (float) ($order->vat_rate ?? 10.0);

            // تعیین سطح دسترسی (Badge) برای محاسبه کمیسیون
            $badgeCode = $order->beneficiary?->badge?->code ?? 'pioneer';

            // دریافت نرخ‌های داینامیک از سرویس ماشین‌حساب
            $rates = $this->calculator->getCommissionRates($badgeCode);

            $ownerDiscountRate = $rates['ow_final_rate'];  // سهم تخفیف خریدار
            $sazeShareRate     = $rates['ssg_final_rate']; // سهم سازه
            $consultantRate    = $rates['co_final_rate'];  // سهم مشاور

            // محاسبه POO (سهم مالک) و PTS (سهم سوپلایر)
            $POO = $this->calculator->calculatePOO($shippingCost, $officialPrice, $weightBeforeScale, $ownerDiscountRate, $vatRate);
            $PTS = $this->calculator->calculatePTS($shippingCost, $officialPrice, $weightBeforeScale, $ownerDiscountRate, $sazeShareRate, $consultantRate, $vatRate);

            // ذخیره مقادیر نهایی در دیتابیس (فریز کردن محاسبات)
            $order->update([
                Order::PAYMENT_OF_OWNER      => $POO,
                Order::PAYMENT_TO_SUPPLIER   => $PTS,
                Order::COMMISSION_RATE       => 1.0, // نرخ پایه
                Order::VAT_RATE              => $vatRate,
                Order::OWNER_DISCOUNT_RATE   => $ownerDiscountRate,
                // وضعیت پرداخت در انتهای تراکنش آپدیت می‌شود
            ]);

            Log::info("Pre-scale calculations finalized for Order {$order->id}", [
                'POO' => $POO, 'PTS' => $PTS, 'OfficialPrice' => $officialPrice
            ]);


            // --- گام ۲: اجرای تراکنش‌های مالی (Money Flow) ---

            // الف) مسیر خریدار (بنفش): کسر کل مبلغ و واریز به حساب واسط
            $this->walletEngine->process('-', $POO, FinancialCodes::WALLET_OWNER, FinancialCodes::STATUS_AVAILABLE, $order, "کسر بابت سفارش {$order->order_number}");
            $this->walletEngine->process('+', $POO, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_AVAILABLE, $order, "واریز موقت سفارش {$order->order_number}");

            // ب) مسیر سوپلایر (قرمز): تخصیص سهم سوپلایر از حساب واسط
            $this->walletEngine->process('-', $PTS, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_AVAILABLE, $order, "تخصیص سهم تامین‌کننده سفارش {$order->order_number}");
            $this->walletEngine->process('+', $PTS, FinancialCodes::WALLET_SUPPLIER, FinancialCodes::STATUS_AVAILABLE, $order, "واریز سهم تامین‌کننده سفارش {$order->order_number}");


            // --- گام ۳: به‌روزرسانی وضعیت سفارش (حیاتی برای فرانت‌) ---

            $order->update([
                'status'         => Order::STATUS_PROCESSING, // تغییر وضعیت به "در حال پردازش"
                'payment_status' => 'paid',                   // وضعیت مالی: پرداخت شده
                'paid_at'        => now(),                    // زمان پرداخت
            ]);

            Log::info("Order {$order->id} processed successfully. Status updated to processing.");
        });
    }
}
