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
        if ($this->isAlreadyProcessed($order)) {
            Log::warning("ProcessPreScalePaymentAction skipped: order {$order->id} already paid.");

            return;
        }

        DB::transaction(function () use ($order) {

            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \RuntimeException("Order {$order->id} not found.");
            }

            if ($this->isAlreadyProcessed($locked)) {
                Log::warning("Race detected: order {$locked->id} already paid.");

                return;
            }

            $locked->load('items', 'beneficiary.badge');

            $officialPrice = (float) $locked->getAttribute(Order::OFFICIAL_PRICE);
            $firstItem = $locked->items->first();

            if (! $firstItem) {
                Log::error("Order {$locked->id} has no items.");
                throw new \RuntimeException('Order items missing for financial processing.');
            }

            if ($officialPrice <= 0) {
                $officialPrice = (float) $firstItem->unit_price;
            }

            $weightBeforeScale = (float) ($locked->getAttribute(Order::WEIGHT_BEFORE_SCALE) ?? 0);
            $shippingCost = (float) ($locked->shipping_cost ?? 0);
            $vatRate = (float) ($locked->vat_rate ?? 10.0);

            $badgeCode = $locked->beneficiary?->badge?->code ?? 'pioneer';
            $rates = $this->calculator->getCommissionRates($badgeCode);

            $ownerDiscountRate = $rates['ow_final_rate'];
            $sazeShareRate = $rates['ssg_final_rate'];
            $consultantRate = $rates['co_final_rate'];

            $POO = $this->calculator->calculatePOO($shippingCost, $officialPrice, $weightBeforeScale, $ownerDiscountRate, $vatRate);
            $PTS = $this->calculator->calculatePTS($shippingCost, $officialPrice, $weightBeforeScale, $ownerDiscountRate, $sazeShareRate, $consultantRate, $vatRate);

            $locked->update([
                Order::PAYMENT_OF_OWNER => $POO,
                Order::PAYMENT_TO_SUPPLIER => $PTS,
                Order::COMMISSION_RATE => 1.0,
                Order::VAT_RATE => $vatRate,
                Order::OWNER_DISCOUNT_RATE => $ownerDiscountRate,
            ]);

            $buyerId = $locked->placer_user_id;
            $realSupplierId = $firstItem->supplier_user_id;
            $systemUserId = 1;
            $systemWalletCode = FinancialCodes::WALLET_SAZE;

            $this->walletEngine->process('-', $POO, FinancialCodes::WALLET_OWNER, FinancialCodes::STATUS_AVAILABLE, $locked, "پرداخت وجه سفارش {$locked->order_number}", $buyerId);
            $this->walletEngine->process('+', $POO, $systemWalletCode, FinancialCodes::STATUS_AVAILABLE, $locked, "دریافت وجه از خریدار برای سفارش {$locked->order_number}", $systemUserId);

            $this->walletEngine->process('-', $PTS, $systemWalletCode, FinancialCodes::STATUS_AVAILABLE, $locked, "تخصیص سهم تامین‌کننده سفارش {$locked->order_number}", $systemUserId);
            $this->walletEngine->process('+', $PTS, FinancialCodes::WALLET_SUPPLIER, FinancialCodes::STATUS_AVAILABLE, $locked, "واریز سهم فروش سفارش {$locked->order_number}", $realSupplierId);

            $locked->update([
                'status' => Order::STATUS_PROCESSING,
                'payment_status' => Order::PAYMENT_PAID,
                'paid_at' => now(),
            ]);

            Log::info("پرداخت سفارش {$locked->id} با موفقیت انجام شد.");
        }, 3);
    }

    private function isAlreadyProcessed(Order $order): bool
    {
        return $order->payment_status === Order::PAYMENT_PAID;
    }
}
