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

            // --- بخش ۱: محاسبه و فریز کردن اعداد (Snapshot) ---
            $op = $order->getAttribute(Order::OFFICIAL_PRICE);
            $wbs = $order->getAttribute(Order::WEIGHT_BEFORE_SCALE);
            $trans = (float) $order->shipping_cost;

            // دریافت ضرایب (مثال)
            $ow = 2.0; $ssg = 3.0; $co = 1.0; $vat = 10.0;

            // محاسبه POO و PTS
            $POO = $this->calculator->calculatePOO($trans, $op, $wbs, $ow, $vat);
            $PTS = $this->calculator->calculatePTS($trans, $op, $wbs, $ow, $ssg, $co, $vat);

            // ذخیره در دیتابیس (با استفاده از ثوابت مدل که شما اشاره کردید)
            $order->update([
                Order::PAYMENT_OF_OWNER => $POO,
                Order::PAYMENT_TO_SUPPLIER => $PTS,
                Order::COMMISSION_RATE => 5.0, // مجموع ضرایب
                Order::VAT_RATE => $vat,
                Order::OWNER_DISCOUNT_RATE => $ow
            ]);

            // --- بخش ۲: مسیر بنفش (خریدار) طبق عکس ۳۹۹ ---
            // 1. صدور سند A (کدش را جداگانه می‌زنیم)
            // 2. کسر از خریدار: -(POO, WO, A)
            $this->walletEngine->process('-', $POO, FinancialCodes::WALLET_OWNER, FinancialCodes::STATUS_AVAILABLE, $order);

            // 3. واریز به شرکت: +(POO, WSSG, A)
            $this->walletEngine->process('+', $POO, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_AVAILABLE, $order);


            // --- بخش ۳: مسیر قرمز (سوپلایر) طبق عکس ۳۹۹ ---
            // 1. صدور سند B
            // 2. برداشت سهم سوپلایر از شرکت: -(PTS, WSSG, A)
            $this->walletEngine->process('-', $PTS, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_AVAILABLE, $order);

            // 3. واریز به سوپلایر: +(PTS, WS, A)
            $this->walletEngine->process('+', $PTS, FinancialCodes::WALLET_SUPPLIER, FinancialCodes::STATUS_AVAILABLE, $order);

            // --- بخش ۴: پایان ---
            // صدور سند C (بارگیری)
            Log::info("Financial flow completed for Order: {$order->id}");
        });
    }
}
