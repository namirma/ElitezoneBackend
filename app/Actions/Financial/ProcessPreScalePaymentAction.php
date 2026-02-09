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

    public function execute(Order $order): void
    {
        DB::transaction(function () use ($order) {

            // --- بخش ۱: محاسبه و آماده‌سازی داده‌ها (Snapshot) ---

            // 1. بازیابی قیمت واحد (با رفع باگ مقدار Null)
            $op = (float) $order->getAttribute(Order::OFFICIAL_PRICE);

            // اگر قیمت رسمی ست نشده بود (0 یا null)، از قیمت اولین آیتم سبد استفاده کن
            if ($op <= 0 && $order->items->isNotEmpty()) {
                $op = (float) $order->items->first()->unit_price;
            }

            // 2. بازیابی وزن و سایر هزینه‌ها
            // استفاده از کست (float) برای اطمینان از تایپ صحیح
            $wbs = (float) ($order->getAttribute(Order::WEIGHT_BEFORE_SCALE) ?? 0);
            $trans = (float) ($order->shipping_cost ?? 0);
            $vat = (float) ($order->vat_rate ?? 10.0);

            // 3. دریافت ضرایب دقیق و داینامیک (جایگزین اعداد ثابت)
            // ضرایب باید بر اساس بج (Badge) مشاور محاسبه شوند، نه عدد ثابت
            $badgeCode = 'pioneer'; // پیش‌فرض
            if ($order->beneficiary && $order->beneficiary->badge) {
                $badgeCode = $order->beneficiary->badge->code;
            }

            // دریافت نرخ‌ها از سرویس مالی
            $rates = $this->calculator->getCommissionRates($badgeCode);

            $owRate  = $rates['ow_final_rate'];  // سهم تخفیف خریدار
            $ssgRate = $rates['ssg_final_rate']; // سهم سازه
            $coRate  = $rates['co_final_rate'];  // سهم مشاور

            // 4. محاسبه POO و PTS توسط ماشین حساب مرکزی
            $POO = $this->calculator->calculatePOO($trans, $op, $wbs, $owRate, $vat);
            $PTS = $this->calculator->calculatePTS($trans, $op, $wbs, $owRate, $ssgRate, $coRate, $vat);

            // 5. ذخیره مقادیر محاسبه شده در سفارش
            $order->update([
                Order::PAYMENT_OF_OWNER => $POO,
                Order::PAYMENT_TO_SUPPLIER => $PTS,
                Order::COMMISSION_RATE => 1.0, // نرخ پایه (TC)
                Order::VAT_RATE => $vat,
                Order::OWNER_DISCOUNT_RATE => $owRate,
                // معمولاً بعد از پرداخت کامل، وضعیت تغییر می‌کند
                // 'status' => Order::STATUS_PAID,
            ]);

            Log::info("Pre-scale payment calculations for Order {$order->id}: POO={$POO}, PTS={$PTS}, OP={$op}");

            // --- بخش ۲: مسیر بنفش (خریدار) ---
            // 1. کسر کل مبلغ از کیف پول خریدار
            $this->walletEngine->process('-', $POO, FinancialCodes::WALLET_OWNER, FinancialCodes::STATUS_AVAILABLE, $order);

            // 2. واریز به حساب واسط (سازه)
            $this->walletEngine->process('+', $POO, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_AVAILABLE, $order);


            // --- بخش ۳: مسیر قرمز (سوپلایر) ---
            // 1. برداشت سهم سوپلایر از حساب واسط
            $this->walletEngine->process('-', $PTS, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_AVAILABLE, $order);

            // 2. واریز به کیف پول سوپلایر
            $this->walletEngine->process('+', $PTS, FinancialCodes::WALLET_SUPPLIER, FinancialCodes::STATUS_AVAILABLE, $order);

            // --- بخش ۴: پایان ---
            Log::info("Financial flow completed successfully for Order: {$order->id}");
        });
    }
}
