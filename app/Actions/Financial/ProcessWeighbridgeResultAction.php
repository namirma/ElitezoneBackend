<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Constants\FinancialCodes;
use App\Models\Order;
use App\Models\Wallet;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Financial\WalletTransactionEngine;
use App\Services\InventoryService;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessWeighbridgeResultAction
{
    // آستانه نادیده گرفتن اختلاف ریالی (زیر ۱۰۰۰ ریال نادیده گرفته می‌شود)
    private const FINANCIAL_TOLERANCE = 1000;

    public function __construct(
        protected WalletTransactionEngine $walletEngine,
        protected SettlementCalculatorService $calculator,
        protected InvoiceGenerator $invoiceGenerator,
        protected InventoryService $inventoryService
    ) {}

    /**
     * اجرای پروسه باسکول، محاسبات مالی و صدور اسناد نهایی
     */
    public function execute(Order $order, float $actualWeight): array
    {
        return DB::transaction(function () use ($order, $actualWeight) {

            Log::info("شروع پردازش باسکول سفارش {$order->id}. وزن قدیم: {$order->weight_before_scale}, وزن جدید: {$actualWeight}");

            // 1. اسنپ‌شات از مقادیر مالی فعلی
            $oldPOO = (float) $order->payment_of_owner;
            $oldPTS = (float) $order->payment_to_supplier;

            // 2. اصلاح موجودی انبار (کسر یا بازگشت)
            $weightDiff = $actualWeight - (float)$order->weight_before_scale;
            // فرض می‌کنیم محصول اصلی در اولین آیتم است
            if ($order->items->isNotEmpty()) {
                $this->inventoryService->updateStockAfterWeighbridge(
                    $order->items->first()->product_variant_id,
                    $weightDiff
                );
            }

            // 3. آماده‌سازی داده‌ها برای محاسبه مجدد
            $badgeCode = $order->beneficiary->badge->code ?? 'pioneer';
            $rates = $this->calculator->getCommissionRates($badgeCode);

            $trans = (float) $order->shipping_cost;
            $vat   = (float) ($order->vat_rate ?? 10.0);

            // بازیابی قیمت واحد (با فال‌بک)
            $op = (float) $order->official_price;
            if ($op <= 0) {
                $op = (float) ($order->items->first()->unit_price ?? 0);
            }

            // 4. محاسبه مقادیر جدید (POO و PTS) بر اساس وزن باسکول
            $calculatedPOO = $this->calculator->calculatePOO(
                $trans, $op, $actualWeight,
                $rates['ow_final_rate'],
                $vat
            );

            $newPTS = $this->calculator->calculatePTS(
                $trans, $op, $actualWeight,
                $rates['ow_final_rate'],
                $rates['ssg_final_rate'],
                $rates['co_final_rate'],
                $vat
            );

            // ============================================================
            // قانون مهم: مبلغ نهایی خریدار هرگز از مبلغ اولیه بیشتر نمی‌شود
            // ============================================================
            $finalPOO = min($calculatedPOO, $oldPOO);

            // اگر مبلغ خریدار سقف خورد (تغییر نکرد)، سهم سوپلایر هم نباید زیاد شود
            // اما اگر مبلغ خریدار کم شد (کسری بار)، سهم سوپلایر هم باید کم شود
            $finalPTS = ($finalPOO == $oldPOO) ? $oldPTS : $newPTS;

            // محاسبه اختلاف‌ها (برای تراکنش‌های کیف پول)
            $diffPOO = $finalPOO - $oldPOO; // همیشه <= 0
            $diffPTS = $finalPTS - $oldPTS;

            $status = 'equal';
            $buyerTxStatus = null;

            // ============================================================
            // سناریو: کسری بار (Deficit) -> بازگشت وجه به خریدار
            // ============================================================
            if ($diffPOO < -self::FINANCIAL_TOLERANCE) {
                $status = 'deficit';

                $refundAmount  = abs($diffPOO); // مبلغ بازگشتی به خریدار
                $reclaimAmount = abs($diffPTS); // مبلغ کسر از سوپلایر

                // --- قدم ۱: پیدا کردن سوپلایر واقعی ---
                // این بخش حیاتی است تا اشتباهاً از خریدار کم نشود
                $realSupplierId = $order->items->first()->supplier_user_id;

                // --- قدم ۲: کسر از سوپلایر ---
                // این تراکنش همیشه انجام می‌شود (حتی اگر منفی شود)
                $this->walletEngine->process(
                    '-',
                    $reclaimAmount,
                    FinancialCodes::WALLET_SUPPLIER,
                    FinancialCodes::STATUS_AVAILABLE,
                    $order,
                    "کسر بابت کسری باسکول",
                    $realSupplierId // <--- ارسال آیدی واقعی (FIXED)
                );

                // --- قدم ۳: تصمیم‌گیری درباره هولد کردن پول خریدار ---
                // بررسی موجودی فعلی سوپلایر
                $supplierWallet = Wallet::where('user_id', $realSupplierId)->first();
                $currentBalance = $supplierWallet ? $supplierWallet->balance : 0;

                // اگر موجودی سوپلایر (بعد از کسر بالا) منفی شده باشد
                // نکته: چون process بالا هنوز در دیتابیس کامیت نشده، ما دستی محاسبه می‌کنیم
                // اما چون process بالا balance را در حافظه مدل کم می‌کند؟ خیر، مدل جدید می‌سازد.
                // پس منطقی‌ترین کار: موجودی فعلی دیتابیس - مبلغ کسر < 0
                $willBeNegative = ($currentBalance - $reclaimAmount) < 0; // یک تقریب امن

                // اگر سوپلایر منفی شد -> پول خریدار هولد شود
                $buyerTxStatus = $willBeNegative ? FinancialCodes::STATUS_HOLD : FinancialCodes::STATUS_AVAILABLE;

                // --- قدم ۴: واریز به خریدار ---
                $this->walletEngine->process(
                    '+',
                    $refundAmount,
                    FinancialCodes::WALLET_OWNER,
                    $buyerTxStatus,
                    $order,
                    "بازگشت وجه ناشی از کسری باسکول"
                );

                if ($willBeNegative) {
                    Log::warning("Supplier {$realSupplierId} wallet negative. Refund held for buyer.");
                }

                // صدور اسناد برگشت
                $this->invoiceGenerator->generate($order, FinancialCodes::DOC_REFUND_RECEIPT_WEIGH);
                $this->invoiceGenerator->generate($order, FinancialCodes::DOC_REFUND_REPORT_WEIGH);
            }

            // ============================================================
            // عملیات نهایی و مشترک
            // ============================================================

            // آپدیت سفارش
            $order->update([
                Order::WEIGHT_AFTER_SCALE => $actualWeight,
                Order::WEIGHING_COMPLETED_AT => now(),
                'status' => Order::STATUS_SHIPPED,
                'payment_of_owner' => $finalPOO,
                'payment_to_supplier' => $finalPTS,
                'final_amount' => $finalPOO,
            ]);

            // صدور مجوز خروج
            $this->invoiceGenerator->generate($order, FinancialCodes::DOC_EXIT_PERMIT);

            Log::info("باسکول نهایی شد. وضعیت: {$status}");

            return [
                'status' => $status,
                'old_weight' => (string) $order->weight_before_scale,
                'new_weight' => (string) $actualWeight,
                'financial_diff' => (string) $diffPOO,
                'final_amount' => (string) $finalPOO,
                'refund_status' => isset($buyerTxStatus) && $buyerTxStatus === FinancialCodes::STATUS_HOLD ? 'held' : 'paid'
            ];
        });
    }
}
