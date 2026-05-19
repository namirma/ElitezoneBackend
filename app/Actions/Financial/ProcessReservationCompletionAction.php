<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Constants\FinancialCodes;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Order;
use App\Models\Wallet;
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

    public function execute(Order $order): void
    {
        if ($this->isAlreadyProcessed($order)) {
            Log::warning("ProcessReservationCompletionAction skipped: order {$order->id} already completed.");

            return;
        }

        Log::info("شروع فرآیند تکمیل وجه برای سفارش رزرو شده {$order->id}");

        DB::transaction(function () use ($order) {

            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \RuntimeException("Order {$order->id} not found.");
            }

            if ($this->isAlreadyProcessed($locked)) {
                Log::warning("Race detected: order {$locked->id} already completed.");

                return;
            }

            $locked->load('items', 'beneficiary.badge');

            $firstItem = $locked->items->first();

            if (! $firstItem) {
                Log::error("Order {$locked->id} has no items.");
                throw new \RuntimeException('Order items missing for reservation completion.');
            }

            $op = (float) ($locked->official_price ?: ($firstItem->unit_price ?? 0));
            $wbs = (float) ($locked->weight_before_scale ?: 1);
            $trans = (float) ($locked->shipping_cost ?: 0);
            $vat = (float) ($locked->vat_rate ?: 10.0);

            $badgeCode = $locked->beneficiary?->badge?->code ?? 'pioneer';
            $rates = $this->calculator->getCommissionRates($badgeCode);

            $ow = $rates['ow_final_rate'];
            $ssg = $rates['ssg_final_rate'];
            $co = $rates['co_final_rate'];

            $POO = $this->calculator->calculatePOO($trans, $op, $wbs, $ow, $vat);
            $PTS = $this->calculator->calculatePTS($trans, $op, $wbs, $ow, $ssg, $co, $vat);

            if ($POO <= 0 || $PTS <= 0) {
                Log::critical("Invalid financial calculation for order {$locked->id}", compact('POO', 'PTS'));
                throw new \RuntimeException('محاسبه مالی سفارش معتبر نیست.');
            }

            $PTR = (float) $locked->payment_to_reserve;

            $remainingAmount = (float) bcsub((string) $POO, (string) $PTR, 4);

            $buyerId = $locked->placer_user_id;
            $realSupplierId = $firstItem->supplier_user_id;
            $systemUserId = 1;
            $systemWalletCode = FinancialCodes::WALLET_SAZE;

            // Guard: check buyer balance against the actual debit amount (POO - PTR),
            // not final_amount - PTR which the controller used as an approximation.
            if ($remainingAmount > 0) {
                $buyerWallet = Wallet::where('user_id', $buyerId)->lockForUpdate()->first();
                if (! $buyerWallet || $buyerWallet->balance < $remainingAmount) {
                    throw new InsufficientBalanceException($remainingAmount, (float) ($buyerWallet?->balance ?? 0));
                }
            }

            Log::info("Order {$locked->id}: POO={$POO}, PTR={$PTR}, Remaining={$remainingAmount}");

            if ($remainingAmount > 0) {
                $this->walletEngine->process('-', $remainingAmount, FinancialCodes::WALLET_OWNER, FinancialCodes::STATUS_AVAILABLE, $locked, "تکمیل وجه سفارش {$locked->order_number}", $buyerId);
                $this->walletEngine->process('+', $remainingAmount, $systemWalletCode, FinancialCodes::STATUS_AVAILABLE, $locked, "دریافت وجه تکمیلی سفارش {$locked->order_number}", $systemUserId);
            } elseif ($remainingAmount < 0) {
                $refundAmount = abs($remainingAmount);
                $this->walletEngine->process('-', $refundAmount, $systemWalletCode, FinancialCodes::STATUS_AVAILABLE, $locked, "بازگشت مابه‌التفاوت افت قیمت سفارش {$locked->order_number}", $systemUserId);
                $this->walletEngine->process('+', $refundAmount, FinancialCodes::WALLET_OWNER, FinancialCodes::STATUS_AVAILABLE, $locked, 'دریافت مابه‌التفاوت افت قیمت رزرو', $buyerId);
            }

            $this->walletEngine->process('-', $PTR, $systemWalletCode, FinancialCodes::STATUS_HOLD, $locked, "خروج از حالت هولد مبلغ رزرو سفارش {$locked->order_number}", $systemUserId);
            $this->walletEngine->process('+', $PTR, $systemWalletCode, FinancialCodes::STATUS_AVAILABLE, $locked, 'تبدیل موجودی هولد رزرو به قطعی', $systemUserId);

            $this->walletEngine->process('-', $PTS, $systemWalletCode, FinancialCodes::STATUS_AVAILABLE, $locked, "تخصیص سهم تامین‌کننده سفارش {$locked->order_number}", $systemUserId);
            $this->walletEngine->process('+', $PTS, FinancialCodes::WALLET_SUPPLIER, FinancialCodes::STATUS_AVAILABLE, $locked, "واریز سهم فروش سفارش {$locked->order_number}", $realSupplierId);

            $locked->update([
                Order::STATUS => Order::STATUS_PROCESSING,
                Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
                Order::PAYMENT_OF_OWNER => $POO,
                Order::PAYMENT_TO_SUPPLIER => $PTS,
                Order::PAID_AT => now(),
            ]);

            $this->invoiceGenerator->generate($locked, FinancialCodes::DOC_FINAL_RECEIPT_OWNER);
            $this->invoiceGenerator->generate($locked, FinancialCodes::DOC_FINAL_REPORT_SUPPLIER);
            $this->invoiceGenerator->generate($locked, FinancialCodes::DOC_LOADING_ORDER);

            Log::info("تکمیل وجه سفارش {$locked->id} انجام شد.");
        }, 3);
    }

    private function isAlreadyProcessed(Order $order): bool
    {
        return $order->status === Order::STATUS_PROCESSING
            && $order->payment_status === Order::PAYMENT_PAID;
    }
}
