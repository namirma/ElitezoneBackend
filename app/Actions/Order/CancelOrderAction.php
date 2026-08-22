<?php

namespace App\Actions\Order;

use App\Constants\FinancialCodes;
use App\Events\Order\OrderCancelled;
use App\Models\Order;
use App\Services\Financial\WalletTransactionEngine;
use App\Services\InventoryService;
use App\Services\Invoice\InvoiceGenerator;
use App\Services\Marketing\CouponService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CancelOrderAction
{
    public function __construct(
        protected InventoryService $inventoryService,
        protected WalletTransactionEngine $walletEngine,
        protected InvoiceGenerator $invoiceGenerator,
        protected RefundOrderToWalletAction $refundAction,
        protected CouponService $couponService,
    ) {}

    /**
     * اجرای عملیات لغو سفارش
     *
     * @param  bool  $isSystemCron  آیا این درخواست توسط کرون‌جاب سیستم ارسال شده است؟
     */
    public function execute(Order $order, ?string $reason = 'لغو سیستمی', bool $refundWallet = true, bool $isSystemCron = false): Order
    {
        // === گارد رزرو — بند ۳.۴ سند ===
        //
        // ⚠️ نسخه‌ی قبلی این گارد **پنجره‌ی لغو خریدار را کاملاً می‌بست.**
        // `OrderActionController::cancel` پنجره را با `isInBuyerCancelWindow()`
        // درست می‌سنجید و اجازه می‌داد، ولی بعد همین‌جا `! $isSystemCron` هر
        // لغوِ غیرکرونی را throw می‌کرد — پس قابلیتی که سند صریحاً می‌خواهد
        // («لغو توسط خریدار بین `reserve_deadline_original` و `reserve_deadline`
        // ⇒ بله») در عمل هرگز کار نمی‌کرد. ستونِ خوانده‌شده هم بازنشسته بود:
        // `reservation_expires_at` را دیگر هیچ مسیرِ رزروی پر نمی‌کند.
        //
        // گارد اینجا می‌ماند و نه در کنترلر: کرانِ انقضا، پنل ادمین و مسیر
        // خریدار هر سه از همین اکشن می‌گذرند، پس تنها جایی که یک‌بار درست
        // نوشتنش همه را پوشش می‌دهد همین است.
        if ($order->status === Order::STATUS_RESERVED
            && ! $isSystemCron
            && ! $order->isInBuyerCancelWindow()) {
            throw new Exception(__('orders.reserve_deadline.cancel_window_closed'));
        }

        $cancelled = DB::transaction(function () use ($order, $reason, $refundWallet, $isSystemCron) {

            // Re-fetch + lock the order row. Admin routes have no idempotency middleware
            // and the system cron can race a manual cancel, so without this a concurrent
            // or retried cancel would restoreStock() and processRefund() twice — a
            // double inventory-restore and a double refund.
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new Exception(__('orders.internal_order_not_found'));
            }

            // Idempotency guard: already cancelled → nothing to do.
            if ($locked->status === Order::STATUS_CANCELLED) {
                return $locked;
            }

            // ── وضعیت مالی قبل از تغییر — برای تصمیم‌گیری درباره اسناد ────
            // باید قبل از update ذخیره شود، چون update مقادیر مدل را تغییر می‌دهد.
            $wasReserved = $locked->status === Order::STATUS_RESERVED;
            $wasPaid = ($locked->payment_status === Order::PAYMENT_PAID);

            // ۱. بازگرداندن موجودی کالا به انبار
            $this->inventoryService->restoreStock($locked);

            // ۲. بازگرداندن وجه به کیف پول (با هندل کردن حساب امانی)
            if ($refundWallet) {
                $this->processRefund($locked);
            }

            // ۲.۵ کوپنِ سفارشِ پرداخت‌نشده آزاد می‌شود (پولی جابه‌جا نشده بود).
            // برای سفارش پرداخت‌شده، کوپن مصرف‌شده می‌ماند و leg تخفیف در
            // RefundOrderToWalletAction برگشت خورده است.
            if (! $wasPaid) {
                $this->couponService->releaseForOrder($locked);
            }

            // ۳. تعیین وضعیت جدید پرداخت
            $newPaymentStatus = $this->resolvePaymentStatus($locked);

            // ۴. آپدیت نهایی سفارش
            $locked->update([
                Order::STATUS => Order::STATUS_CANCELLED,
                Order::PAYMENT_STATUS => $newPaymentStatus,
                Order::CANCEL_REASON => $reason,
            ]);

            // ۵. صدور اسناد لغو (Doc j همیشه، Doc i فقط اگر استرداد شد) ─────
            if ($wasReserved || $wasPaid) {
                DB::afterCommit(function () use ($locked, $refundWallet) {
                    $this->invoiceGenerator->generateCancellationDocs(
                        $locked,
                        withRefundReceipt: (bool) $refundWallet
                    );
                });
            }

            Log::channel('orders')->info("سفارش شماره {$locked->order_number} با موفقیت لغو شد.", [
                'reason' => $reason,
                'refunded' => $refundWallet,
                'is_system_cron' => $isSystemCron,
            ]);

            return $locked;
        });

        // ایونت بعد از commit ارسال می‌شود؛ صف با after_commit=false ممکن است
        // listener را قبل از commit اجرا کند و وضعیت لغو را نبیند.
        OrderCancelled::dispatch($cancelled);

        return $cancelled;
    }

    /**
     * پردازش بازگشت وجه با در نظر گرفتن حساب‌های امانی (Hold)
     */
    private function processRefund(Order $order): void
    {
        // الف) اگر سفارش رزرو بوده: باید پول هولد شده در سازه به خریدار برگردد
        if ($order->status === Order::STATUS_RESERVED) {
            $reserveAmount = (float) $order->{Order::PAYMENT_TO_RESERVE};

            if ($reserveAmount > 0) {
                // خروج از هولد کیف پول سازه
                $this->walletEngine->debitSystem($order, $reserveAmount, FinancialCodes::STATUS_HOLD, "خروج از هولد به دلیل لغو رزرو سفارش {$order->order_number}");
                // بازگشت به کیف پول آزاد خریدار
                $this->walletEngine->creditBuyer($order, $reserveAmount, FinancialCodes::STATUS_AVAILABLE, 'بازگشت وجه بیعانه به دلیل لغو سفارش');
            }
        }
        // ب) اگر سفارش خرید مستقیم بوده (بدون رزرو و هولد)
        elseif ($order->payment_status === Order::PAYMENT_PAID) {
            // استفاده از اکشن مجزا به جای متد مدل
            $this->refundAction->execute($order);
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
