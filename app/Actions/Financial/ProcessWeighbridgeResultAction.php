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
        if ($this->isAlreadyProcessed($order)) {
            Log::warning("ProcessWeighbridgeResultAction skipped: order {$order->id} already weighed.");
            return $this->buildAlreadyProcessedResponse($order);
        }

        if ($actualWeight <= 0) {
            throw new \InvalidArgumentException('وزن باسکول باید بزرگ‌تر از صفر باشد.');
        }

        return DB::transaction(function () use ($order, $actualWeight) {

            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (!$locked) {
                throw new \RuntimeException("Order {$order->id} not found.");
            }

            if ($this->isAlreadyProcessed($locked)) {
                Log::warning("Race detected: order {$locked->id} already weighed.");
                return $this->buildAlreadyProcessedResponse($locked);
            }

            $locked->load('items', 'beneficiary.badge');

            Log::info("شروع پردازش باسکول سفارش {$locked->id}.");

            $oldPOO = (float) $locked->payment_of_owner;
            $oldPTS = (float) $locked->payment_to_supplier;

            $weightDiff = $actualWeight - (float) $locked->weight_before_scale;
            if ($locked->items->isNotEmpty()) {
                $this->inventoryService->updateStockAfterWeighbridge(
                    $locked->items->first()->product_variant_id,
                    $weightDiff
                );
            }

            $badgeCode = $locked->beneficiary->badge->code ?? 'pioneer';
            $rates = $this->calculator->getCommissionRates($badgeCode);
            $trans = (float) $locked->shipping_cost;
            $vat   = (float) ($locked->vat_rate ?? 10.0);
            $op = ((float) $locked->official_price > 0)
                ? (float) $locked->official_price
                : (float) ($locked->items->first()->unit_price ?? 0);

            $newPOO = $this->calculator->calculatePOO($trans, $op, $actualWeight, $rates['ow_final_rate'], $vat);
            $newPTS = $this->calculator->calculatePTS($trans, $op, $actualWeight, $rates['ow_final_rate'], $rates['ssg_final_rate'], $rates['co_final_rate'], $vat);

            $diffPOO = $newPOO - $oldPOO;
            $diffPTS = $newPTS - $oldPTS;

            $status = 'equal';
            $orderStatus = Order::STATUS_SHIPPED;

            $buyerId = $locked->placer_user_id;
            $realSupplierId = $locked->items->first()->supplier_user_id;
            $systemUserId = 1;
            $systemWalletCode = FinancialCodes::WALLET_SAZE;

            if ($diffPOO > self::FINANCIAL_TOLERANCE) {
                $status = 'surplus';
                $chargeAmount = abs($diffPOO);
                $payoutAmount = abs($diffPTS);

                $this->walletEngine->process('-', $chargeAmount, FinancialCodes::WALLET_OWNER, FinancialCodes::STATUS_AVAILABLE, $locked, "بدهی ایجاد شده بابت سرک باسکول", $buyerId);
                $this->walletEngine->process('+', $chargeAmount, $systemWalletCode, FinancialCodes::STATUS_AVAILABLE, $locked, "دریافت بدهی خریدار بابت سرک باسکول سفارش {$locked->order_number}", $systemUserId);

                $this->walletEngine->process('-', $payoutAmount, $systemWalletCode, FinancialCodes::STATUS_AVAILABLE, $locked, "پرداخت سهم تامین‌کننده بابت سرک باسکول سفارش {$locked->order_number}", $systemUserId);
                $this->walletEngine->process('+', $payoutAmount, FinancialCodes::WALLET_SUPPLIER, FinancialCodes::STATUS_AVAILABLE, $locked, "دریافت وجه بابت سرک باسکول", $realSupplierId);

                $orderStatus = Order::STATUS_PENDING_SURPLUS_PAYMENT;

                $this->invoiceGenerator->generate($locked, FinancialCodes::DOC_MODIFIED_INVOICE_OWNER);
                $this->invoiceGenerator->generate($locked, FinancialCodes::DOC_MODIFIED_INVOICE_SUPPLIER);
                $this->invoiceGenerator->generate($locked, FinancialCodes::DOC_SUPPLEMENTARY_RECEIPT);
                $this->invoiceGenerator->generate($locked, FinancialCodes::DOC_SUPPLEMENTARY_REPORT);
            } elseif ($diffPOO < -self::FINANCIAL_TOLERANCE) {
                $status = 'deficit';
                $refundAmount = abs($diffPOO);
                $reclaimAmount = abs($diffPTS);

                $this->walletEngine->process('-', $refundAmount, $systemWalletCode, FinancialCodes::STATUS_AVAILABLE, $locked, "پرداخت وجه کسری باسکول سفارش {$locked->order_number} به خریدار", $systemUserId);
                $this->walletEngine->process('+', $refundAmount, FinancialCodes::WALLET_OWNER, FinancialCodes::STATUS_AVAILABLE, $locked, "بازگشت وجه ناشی از کسری باسکول", $buyerId);

                $this->walletEngine->process('-', $reclaimAmount, FinancialCodes::WALLET_SUPPLIER, FinancialCodes::STATUS_AVAILABLE, $locked, "کسر بابت کسری باسکول", $realSupplierId);
                $this->walletEngine->process('+', $reclaimAmount, $systemWalletCode, FinancialCodes::STATUS_AVAILABLE, $locked, "بازپس‌گیری از تامین‌کننده بابت کسری باسکول سفارش {$locked->order_number}", $systemUserId);

                $this->invoiceGenerator->generate($locked, FinancialCodes::DOC_MODIFIED_INVOICE_OWNER);
                $this->invoiceGenerator->generate($locked, FinancialCodes::DOC_MODIFIED_INVOICE_SUPPLIER);
                $this->invoiceGenerator->generate($locked, FinancialCodes::DOC_REFUND_REPORT_WEIGH);
                $this->invoiceGenerator->generate($locked, FinancialCodes::DOC_EXIT_PERMIT);
            } else {
                $this->invoiceGenerator->generate($locked, FinancialCodes::DOC_EXIT_PERMIT);
            }

            $base = $trans + ($op * $actualWeight);
            $vatMultiplier = ($vat / 100) / (1 + ($vat / 100));

            $consultantUserId = $locked->beneficiary_user_id;
            $coRate = $rates['co_final_rate'] / 100;
            $candidateGross = $base * $coRate * (1 + ($vat / 100));
            $consultantGross = 0;

            if ($consultantUserId && $consultantUserId !== $buyerId && $candidateGross > 0) {
                $consultantVat = $candidateGross * $vatMultiplier;
                $consultantNet = $candidateGross - $consultantVat;

                $this->walletEngine->process('-', $candidateGross, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_AVAILABLE, $locked, "تخصیص سهم مشاور فنی سفارش {$locked->order_number}", $systemUserId);
                $this->walletEngine->process('+', $consultantNet, FinancialCodes::WALLET_CONSULTANT, FinancialCodes::STATUS_HOLD, $locked, "کمیسیون امانی مشاور فنی", $consultantUserId);
                $this->walletEngine->process('+', $consultantVat, FinancialCodes::WALLET_VAT, FinancialCodes::STATUS_HOLD, $locked, "مالیات کمیسیون مشاور فنی امانی", $systemUserId);

                $consultantGross = $candidateGross;
            }

            $supplierVat = $newPTS * $vatMultiplier;
            if ($supplierVat > 0) {
                $this->walletEngine->process('-', $supplierVat, FinancialCodes::WALLET_SUPPLIER, FinancialCodes::STATUS_AVAILABLE, $locked, "کسر مالیات امانی سفارش {$locked->order_number}", $realSupplierId);
                $this->walletEngine->process('+', $supplierVat, FinancialCodes::WALLET_VAT, FinancialCodes::STATUS_HOLD, $locked, "مالیات تامین‌کننده امانی", $systemUserId);
            }

            $sazeGross = ($newPOO - $newPTS) - $consultantGross;
            $sazeVat = $sazeGross * $vatMultiplier;
            if ($sazeVat > 0) {
                $this->walletEngine->process('-', $sazeVat, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_AVAILABLE, $locked, "کسر مالیات بر ارزش افزوده پلتفرم", $systemUserId);
                $this->walletEngine->process('+', $sazeVat, FinancialCodes::WALLET_VAT, FinancialCodes::STATUS_HOLD, $locked, "مالیات گروه سازه امانی", $systemUserId);
            }

            $locked->update([
                Order::WEIGHT_AFTER_SCALE    => $actualWeight,
                Order::WEIGHING_COMPLETED_AT => now(),
                'status'                     => $orderStatus,
                'payment_of_owner'           => $newPOO,
                'payment_to_supplier'        => $newPTS,
                'payment_to_consultant'      => $consultantGross,
                'final_amount'               => $newPOO,
            ]);

            Log::info("باسکول نهایی شد. وضعیت: {$status}");

            return [
                'status'         => $status,
                'old_weight'     => (string) $locked->weight_before_scale,
                'new_weight'     => (string) $actualWeight,
                'financial_diff' => (string) abs($diffPOO),
                'final_amount'   => (string) $newPOO,
            ];
        }, 3);
    }

    private function isAlreadyProcessed(Order $order): bool
    {
        return $order->weighing_completed_at !== null;
    }

    private function buildAlreadyProcessedResponse(Order $order): array
    {
        return [
            'status'         => 'already_processed',
            'old_weight'     => (string) $order->weight_before_scale,
            'new_weight'     => (string) $order->weight_after_scale,
            'financial_diff' => '0',
            'final_amount'   => (string) $order->final_amount,
        ];
    }
}
