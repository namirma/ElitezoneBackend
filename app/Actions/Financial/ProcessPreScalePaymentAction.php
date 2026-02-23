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

            // --- گام ۱: محاسبه دقیق سهم‌ها ---
            $officialPrice = (float) $order->getAttribute(Order::OFFICIAL_PRICE);
            if ($officialPrice <= 0 && $order->items->isNotEmpty()) {
                $officialPrice = (float) $order->items->first()->unit_price;
            }

            $weightBeforeScale = (float) ($order->getAttribute(Order::WEIGHT_BEFORE_SCALE) ?? 0);
            $shippingCost      = (float) ($order->shipping_cost ?? 0);
            $vatRate           = (float) ($order->vat_rate ?? 10.0);

            $badgeCode = $order->beneficiary?->badge?->code ?? 'pioneer';
            $rates = $this->calculator->getCommissionRates($badgeCode);

            $ownerDiscountRate = $rates['ow_final_rate'];
            $sazeShareRate     = $rates['ssg_final_rate'];
            $consultantRate    = $rates['co_final_rate'];

            $POO = $this->calculator->calculatePOO($shippingCost, $officialPrice, $weightBeforeScale, $ownerDiscountRate, $vatRate);
            $PTS = $this->calculator->calculatePTS($shippingCost, $officialPrice, $weightBeforeScale, $ownerDiscountRate, $sazeShareRate, $consultantRate, $vatRate);

            $order->update([
                Order::PAYMENT_OF_OWNER      => $POO,
                Order::PAYMENT_TO_SUPPLIER   => $PTS,
                Order::COMMISSION_RATE       => 1.0,
                Order::VAT_RATE              => $vatRate,
                Order::OWNER_DISCOUNT_RATE   => $ownerDiscountRate,
            ]);

            // --- گام ۲: اجرای تراکنش‌های مالی (Money Flow) با شناسه‌های صحیح ---

            $buyerId = $order->placer_user_id;
            $realSupplierId = $order->items->first()->supplier_user_id;
            $systemUserId = 1; // شناسه ادمین/سازه
            $systemWalletCode = FinancialCodes::WALLET_SAZE;

            // الف) کسر از خریدار و واریز به سیستم
            $this->walletEngine->process('-', $POO, FinancialCodes::WALLET_OWNER, FinancialCodes::STATUS_AVAILABLE, $order, "پرداخت وجه سفارش {$order->order_number}", $buyerId);
            $this->walletEngine->process('+', $POO, $systemWalletCode, FinancialCodes::STATUS_AVAILABLE, $order, "دریافت وجه از خریدار برای سفارش {$order->order_number}", $systemUserId);

            // ب) کسر سهم سوپلایر از سیستم و واریز به سوپلایر
            $this->walletEngine->process('-', $PTS, $systemWalletCode, FinancialCodes::STATUS_AVAILABLE, $order, "تخصیص سهم تامین‌کننده سفارش {$order->order_number}", $systemUserId);
            $this->walletEngine->process('+', $PTS, FinancialCodes::WALLET_SUPPLIER, FinancialCodes::STATUS_AVAILABLE, $order, "واریز سهم فروش سفارش {$order->order_number}", $realSupplierId);


            // --- گام ۳: به‌روزرسانی وضعیت سفارش ---
            $order->update([
                'status'         => Order::STATUS_PROCESSING,
                'payment_status' => Order::PAYMENT_PAID,
                'payment_method' => 'wallet',
                'paid_at'        => now(),
            ]);

            Log::info("پرداخت سفارش {$order->id} با موفقیت انجام شد و توزیع مالی تکمیل گردید.");
        });
    }
}
