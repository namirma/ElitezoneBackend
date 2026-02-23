<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Financial\WalletTransactionEngine;
use App\Services\InventoryService;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessWeighbridgeResultAction
{
    private const FINANCIAL_TOLERANCE = 1000;

    public function __construct(
        protected WalletTransactionEngine $walletEngine,
        protected SettlementCalculatorService $calculator,
        protected InvoiceGenerator $invoiceGenerator,
        protected InventoryService $inventoryService
    ) {}

    public function execute(Order $order, float $actualWeight): array
    {
        return DB::transaction(function () use ($order, $actualWeight) {

            Log::info("شروع پردازش باسکول سفارش {$order->id}. وزن قدیم: {$order->weight_before_scale}, وزن جدید: {$actualWeight}");

            $oldPOO = (float) $order->payment_of_owner;
            $oldPTS = (float) $order->payment_to_supplier;

            // ۱. اصلاح موجودی انبار بر اساس وزن باسکول
            $weightDiff = $actualWeight - (float)$order->weight_before_scale;
            if ($order->items->isNotEmpty()) {
                $this->inventoryService->updateStockAfterWeighbridge(
                    $order->items->first()->product_variant_id,
                    $weightDiff
                );
            }

            // ۲. آماده‌سازی داده‌ها برای محاسبه مجدد
            $badgeCode = $order->beneficiary->badge->code ?? 'pioneer';
            $rates = $this->calculator->getCommissionRates($badgeCode);
            $trans = (float) $order->shipping_cost;
            $vat   = (float) ($order->vat_rate ?? 10.0);
            $op = (float) $order->official_price > 0 ? (float) $order->official_price : (float) ($order->items->first()->unit_price ?? 0);

            // ۳. محاسبه مقادیر جدید
            $newPOO = $this->calculator->calculatePOO($trans, $op, $actualWeight, $rates['ow_final_rate'], $vat);
            $newPTS = $this->calculator->calculatePTS($trans, $op, $actualWeight, $rates['ow_final_rate'], $rates['ssg_final_rate'], $rates['co_final_rate'], $vat);

            $diffPOO = $newPOO - $oldPOO;
            $diffPTS = $newPTS - $oldPTS;

            $status = 'equal';
            $orderStatus = Order::STATUS_SHIPPED;

            $buyerId = $order->placer_user_id;
            $realSupplierId = $order->items->first()->supplier_user_id;
            $systemUserId = 1;
            $systemWalletCode = FinancialCodes::WALLET_SAZE;

            // ============================================================
            // سناریو ۱: سرک بار (Surplus)
            // ============================================================
            if ($diffPOO > self::FINANCIAL_TOLERANCE) {
                $status = 'surplus';
                $chargeAmount = abs($diffPOO);
                $payoutAmount = abs($diffPTS);

                $this->walletEngine->process('-', $chargeAmount, FinancialCodes::WALLET_OWNER, FinancialCodes::STATUS_AVAILABLE, $order, "بدهی ایجاد شده بابت سرک باسکول", $buyerId);
                $this->walletEngine->process('+', $chargeAmount, $systemWalletCode, FinancialCodes::STATUS_AVAILABLE, $order, "دریافت بدهی خریدار بابت سرک باسکول سفارش {$order->order_number}", $systemUserId);

                $this->walletEngine->process('-', $payoutAmount, $systemWalletCode, FinancialCodes::STATUS_AVAILABLE, $order, "پرداخت سهم تامین‌کننده بابت سرک باسکول سفارش {$order->order_number}", $systemUserId);
                $this->walletEngine->process('+', $payoutAmount, FinancialCodes::WALLET_SUPPLIER, FinancialCodes::STATUS_AVAILABLE, $order, "دریافت وجه بابت سرک باسکول", $realSupplierId);

                $orderStatus = Order::STATUS_PENDING_SURPLUS_PAYMENT;

                // صدور اسناد دقیقاً طبق فایل FinancialCodes
                $this->invoiceGenerator->generate($order, FinancialCodes::DOC_MODIFIED_INVOICE_OWNER);    // LO
                $this->invoiceGenerator->generate($order, FinancialCodes::DOC_MODIFIED_INVOICE_SUPPLIER); // LS
                $this->invoiceGenerator->generate($order, FinancialCodes::DOC_SUPPLEMENTARY_RECEIPT);     // J
                $this->invoiceGenerator->generate($order, FinancialCodes::DOC_SUPPLEMENTARY_REPORT);      // K
            }
            // ============================================================
            // سناریو ۲: کسری بار (Deficit)
            // ============================================================
            elseif ($diffPOO < -self::FINANCIAL_TOLERANCE) {
                $status = 'deficit';
                $refundAmount = abs($diffPOO);
                $reclaimAmount = abs($diffPTS);

                $this->walletEngine->process('-', $refundAmount, $systemWalletCode, FinancialCodes::STATUS_AVAILABLE, $order, "پرداخت وجه کسری باسکول سفارش {$order->order_number} به خریدار", $systemUserId);
                $this->walletEngine->process('+', $refundAmount, FinancialCodes::WALLET_OWNER, FinancialCodes::STATUS_AVAILABLE, $order, "بازگشت وجه ناشی از کسری باسکول", $buyerId);

                $this->walletEngine->process('-', $reclaimAmount, FinancialCodes::WALLET_SUPPLIER, FinancialCodes::STATUS_AVAILABLE, $order, "کسر بابت کسری باسکول", $realSupplierId);
                $this->walletEngine->process('+', $reclaimAmount, $systemWalletCode, FinancialCodes::STATUS_AVAILABLE, $order, "بازپس‌گیری از تامین‌کننده بابت کسری باسکول سفارش {$order->order_number}", $systemUserId);

                // صدور اسناد دقیقاً طبق فایل FinancialCodes (کدهای من‌درآوردی حذف شدند)
                $this->invoiceGenerator->generate($order, FinancialCodes::DOC_MODIFIED_INVOICE_OWNER);    // LO
                $this->invoiceGenerator->generate($order, FinancialCodes::DOC_MODIFIED_INVOICE_SUPPLIER); // LS
                $this->invoiceGenerator->generate($order, FinancialCodes::DOC_REFUND_REPORT_WEIGH);       // M
                $this->invoiceGenerator->generate($order, FinancialCodes::DOC_EXIT_PERMIT);               // I
            }
            // ============================================================
            // سناریو ۳: بار برابر (Equal)
            // ============================================================
            else {
                $this->invoiceGenerator->generate($order, FinancialCodes::DOC_EXIT_PERMIT);               // I
            }

            // ============================================================
            // ۴. توزیع حساب‌های امانی: سهم مشاور فنی و مالیات بر ارزش افزوده
            // ============================================================
            $base = $trans + ($op * $actualWeight);
            $vatMultiplier = ($vat / 100) / (1 + ($vat / 100));

            // الف) تفکیک و هولد سهم مشاور فنی
            $consultantUserId = $order->beneficiary_user_id;
            $coRate = $rates['co_final_rate'] / 100;
            $consultantGross = $base * $coRate * (1 + ($vat / 100));

            if ($consultantUserId && $consultantUserId !== $buyerId && $consultantGross > 0) {
                $consultantVat = $consultantGross * $vatMultiplier;
                $consultantNet = $consultantGross - $consultantVat;

                $this->walletEngine->process('-', $consultantGross, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_AVAILABLE, $order, "تخصیص سهم مشاور فنی سفارش {$order->order_number}", $systemUserId);
                $this->walletEngine->process('+', $consultantNet, FinancialCodes::WALLET_CONSULTANT, FinancialCodes::STATUS_HOLD, $order, "کمیسیون امانی مشاور فنی تا صدور فاکتور مودیان", $consultantUserId);
                $this->walletEngine->process('+', $consultantVat, FinancialCodes::WALLET_VAT, FinancialCodes::STATUS_HOLD, $order, "مالیات بر ارزش افزوده کمیسیون مشاور فنی (امانی)", $systemUserId);
            } else {
                $consultantGross = 0;
            }

            // ب) تفکیک و هولد مالیات تامین‌کننده (سوپلایر)
            $supplierVat = $newPTS * $vatMultiplier;
            if ($supplierVat > 0) {
                $this->walletEngine->process('-', $supplierVat, FinancialCodes::WALLET_SUPPLIER, FinancialCodes::STATUS_AVAILABLE, $order, "کسر مالیات امانی سفارش {$order->order_number} تا زمان تسویه مودیان", $realSupplierId);
                $this->walletEngine->process('+', $supplierVat, FinancialCodes::WALLET_VAT, FinancialCodes::STATUS_HOLD, $order, "مالیات بر ارزش افزوده تامین‌کننده (امانی)", $systemUserId);
            }

            // ج) تفکیک و هولد مالیات گروه سازه
            $sazeGross = ($newPOO - $newPTS) - $consultantGross;
            $sazeVat = $sazeGross * $vatMultiplier;
            if ($sazeVat > 0) {
                $this->walletEngine->process('-', $sazeVat, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_AVAILABLE, $order, "کسر مالیات بر ارزش افزوده پلتفرم", $systemUserId);
                $this->walletEngine->process('+', $sazeVat, FinancialCodes::WALLET_VAT, FinancialCodes::STATUS_HOLD, $order, "مالیات بر ارزش افزوده گروه سازه (امانی)", $systemUserId);
            }

            // ۵. آپدیت نهایی سفارش با مقادیر جدید
            $order->update([
                Order::WEIGHT_AFTER_SCALE => $actualWeight,
                Order::WEIGHING_COMPLETED_AT => now(),
                'status' => $orderStatus,
                'payment_of_owner' => $newPOO,
                'payment_to_supplier' => $newPTS,
                'payment_to_consultant' => $consultantGross,
                'final_amount' => $newPOO,
            ]);

            Log::info("باسکول نهایی شد و مبالغ امانی مشاور و مالیات با موفقیت تفکیک و هولد شدند. وضعیت: {$status}");

            return [
                'status'         => $status,
                'old_weight'     => (string) $order->weight_before_scale,
                'new_weight'     => (string) $actualWeight,
                'financial_diff' => (string) abs($diffPOO),
                'final_amount'   => (string) $newPOO,
            ];
        });
    }
}
