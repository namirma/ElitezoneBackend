<?php

namespace App\Actions\Order;

use App\Constants\FinancialCodes;
use App\Events\Order\OrderCancelled;
use App\Models\Order;
use App\Services\Financial\WalletTransactionEngine;
use App\Services\InventoryService;
use App\Services\Invoice\InvoiceGenerator;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CancelOrderAction
{
    public function __construct(
        protected InventoryService $inventoryService,
        protected WalletTransactionEngine $walletEngine,
        protected InvoiceGenerator $invoiceGenerator,
    ) {}

    /**
     * اجرای عملیات لغو سفارش
     *
     * @param  bool  $isSystemCron  آیا این درخواست توسط کرون‌جاب سیستم ارسال شده است؟
     */
    public function execute(Order $order, ?string $reason = 'لغو سیستمی', bool $refundWallet = true, bool $isSystemCron = false): Order
    {
        // === گارد امنیتی طبق فلوچارت ===
        if (in_array($order->status, [Order::STATUS_RESERVED, Order::STATUS_RESERVED_EXTENDED])) {
            if ($order->reservation_expires_at && $order->reservation_expires_at->isFuture()) {
                throw new Exception('سفارشات رزرو شده تا پیش از پایان زمان انقضا قابل لغو نیستند.');
            }

            if (! $isSystemCron) {
                throw new Exception('سفارشات رزرو شده منقضی، تنها توسط سیستم (به صورت خودکار) لغو می‌شوند.');
            }
        }

        return DB::transaction(function () use ($order, $reason, $refundWallet, $isSystemCron) {

            // ── وضعیت مالی قبل از تغییر — برای تصمیم‌گیری درباره اسناد ────
            // باید قبل از update ذخیره شود، چون update مقادیر مدل را تغییر می‌دهد.
            $wasReserved = in_array($order->status, [
                Order::STATUS_RESERVED,
                Order::STATUS_RESERVED_EXTENDED,
            ]);
            $wasPaid = ($order->payment_status === Order::PAYMENT_PAID);

            // ۱. بازگرداندن موجودی کالا به انبار
            $this->inventoryService->restoreStock($order);

            // ۲. بازگرداندن وجه به کیف پول (با هندل کردن حساب امانی)
            if ($refundWallet) {
                $this->processRefund($order);
            }

            // ۳. تعیین وضعیت جدید پرداخت
            $newPaymentStatus = $this->resolvePaymentStatus($order);

            // ۴. آپدیت نهایی سفارش
            $order->update([
                Order::STATUS => Order::STATUS_CANCELLED,
                Order::PAYMENT_STATUS => $newPaymentStatus,
                Order::CANCEL_REASON => $reason,
            ]);

            // ۵. ارسال ایونت
            OrderCancelled::dispatch($order);

            // ۶. صدور اسناد لغو (فقط اگر تراکنش مالی وجود داشته) ──────────
            // i — رسید استرداد وجه برای خریدار (فقط اگر واقعاً استرداد شد)
            if ($refundWallet && ($wasReserved || $wasPaid)) {
                $this->invoiceGenerator->generate($order, FinancialCodes::DOC_REFUND_RECEIPT);
            }
            // j — گزارش لغو برای سوپلایر (هر بار که سفارش پولی داشت)
            if ($wasReserved || $wasPaid) {
                $this->invoiceGenerator->generate($order, FinancialCodes::DOC_CANCEL_REPORT);
            }

            Log::channel('orders')->info("سفارش شماره {$order->order_number} با موفقیت لغو شد.", [
                'reason' => $reason,
                'refunded' => $refundWallet,
                'is_system_cron' => $isSystemCron,
            ]);

            return $order;
        });
    }

    /**
     * پردازش بازگشت وجه با در نظر گرفتن حساب‌های امانی (Hold)
     */
    private function processRefund(Order $order): void
    {
        $buyerId = $order->placer_user_id;
        $systemUserId = (int) config('financial.system_user_id', 1);

        // الف) اگر سفارش رزرو بوده: باید پول هولد شده در سازه به خریدار برگردد
        if (in_array($order->status, [Order::STATUS_RESERVED, Order::STATUS_RESERVED_EXTENDED])) {
            $reserveAmount = (float) $order->{Order::PAYMENT_TO_RESERVE};

            if ($reserveAmount > 0) {
                // خروج از هولد کیف پول سازه
                $this->walletEngine->process('-', $reserveAmount, FinancialCodes::WALLET_SAZE, FinancialCodes::STATUS_HOLD, $order, "خروج از هولد به دلیل لغو رزرو سفارش {$order->order_number}", $systemUserId);
                // بازگشت به کیف پول آزاد خریدار
                $this->walletEngine->process('+', $reserveAmount, FinancialCodes::WALLET_OWNER, FinancialCodes::STATUS_AVAILABLE, $order, 'بازگشت وجه بیعانه به دلیل لغو سفارش', $buyerId);
            }
        }
        // ب) اگر سفارش خرید مستقیم بوده (بدون رزرو و هولد)
        elseif ($order->payment_status === Order::PAYMENT_PAID) {
            // استفاده از متد بازگشت وجه عادی که قبلا نوشتیم
            $order->refundToWallet();
        }
    }

    private function resolvePaymentStatus(Order $order): string
    {
        $currentStatus = $order->fresh()->{Order::PAYMENT_STATUS};

        if ($currentStatus === Order::PAYMENT_PENDING) {
            return Order::PAYMENT_FAILED;
        }

        return $currentStatus;
    }
}
