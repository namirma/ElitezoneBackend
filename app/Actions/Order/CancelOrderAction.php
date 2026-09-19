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
use Throwable;

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
     * اجرای عملیات لغو سفارش.
     *
     * @param  bool  $isSystemCron  پنجره‌ی لغوِ خریدار را رد می‌کند. نامش تاریخی
     *                              است: **ادمین هم** آن را می‌فرستد
     *                              (`AdminOrderController::forceCancel`)، پس
     *                              معنایش «من کرانم» نیست، «این لغو مشمول
     *                              محدودیتِ خریدار نیست» است.
     * @param  bool  $requireExpiredReserve  فقط کرانِ انقضا. سفارش باید هنوز
     *                                       `RESERVED` و مهلت+ارفاقش گذشته باشد، وگرنه
     *                                       بی‌صدا رد می‌شود. جدا از `$isSystemCron` است
     *                                       چون آن پرچم دو مصرف‌کننده دارد و بستنِ این
     *                                       قاعده رویش، لغوِ اجباریِ ادمین را می‌شکست.
     */
    public function execute(
        Order $order,
        ?string $reason = 'لغو سیستمی',
        bool $refundWallet = true,
        bool $isSystemCron = false,
        bool $requireExpiredReserve = false,
    ): Order {
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

        $cancelled = DB::transaction(function () use ($order, $reason, $refundWallet, $isSystemCron, $requireExpiredReserve) {

            // Re-fetch + lock the order row. Admin routes have no idempotency middleware
            // and the system cron can race a manual cancel, so without this a concurrent
            // or retried cancel would restoreStock() and processRefund() twice — a
            // double inventory-restore and a double refund.
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new Exception(__('orders.internal_order_not_found'));
            }

            // larastan نتیجه‌ی `lockForUpdate()->first()` را `Model` می‌بیند نه
            // `Order`؛ بعد از گاردِ بالا این تنگ‌سازی درست است و گاردِ کرانِ
            // پایین‌تر را بدون افزودن ورودی به baseline ممکن می‌کند.
            /** @var Order $locked */

            // Idempotency guard: already cancelled → nothing to do.
            if ($locked->status === Order::STATUS_CANCELLED) {
                return $locked;
            }

            // ⚠️ گاردِ کران — بین انتخابِ ردیف و قفل‌شدنش دنیا عوض می‌شود.
            //
            // کران فهرستِ رزروهای منقضی را می‌گیرد و بعد یکی‌یکی قفل می‌کند.
            // در همان فاصله خریدار می‌تواند رزرو را **تکمیل و پرداخت** کند
            // (`ProcessReservationCompletionAction`) یا مهلتش را **تمدید** کند.
            // گاردِ بالا فقط `CANCELLED` را می‌گرفت، پس سفارشِ تازه‌پرداخت‌شده
            // لغو می‌شد: موجودی به انبار برمی‌گشت (فروشِ دوباره‌ی همان کالا) و
            // پولِ تأمین‌کننده پس گرفته می‌شد — بی‌آنکه دفتر نامتوازن شود، پس
            // `financial:doctor` هم چیزی نمی‌دید.
            //
            // فقط کرانِ انقضا این پرچم را می‌فرستد. لغوِ اجباریِ ادمین عمداً
            // می‌تواند سفارشِ `processing` را هم ببندد.
            if ($requireExpiredReserve && (
                $locked->{Order::STATUS} !== Order::STATUS_RESERVED
                || ! $locked->isReserveWindowExpired()
            )) {
                Log::channel('orders')->info(
                    "لغو خودکار سفارش {$locked->order_number} رد شد — وضعیت یا مهلت پیش از قفل تغییر کرده بود.",
                    ['status' => $locked->{Order::STATUS}]
                );

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
                    // ⚠️ این callback **بعد از commit** ولی هنوز داخلِ `execute()` اجرا
                    // می‌شود. خطای ساختِ PDF (مثلاً فونتِ غایب → ۵۰۳) از این‌جا بیرون
                    // می‌پرید در حالی که سفارش **لغو و مسترد شده بود**: ادمین «قابل لغو
                    // نیست» می‌دید، `OrderCancelled` هرگز dispatch نمی‌شد (مشتری خبری
                    // نمی‌گرفت) و حلقه‌ی کرون روی همین سفارش متوقف می‌شد. لغو انجام
                    // شده؛ سندِ نساخته فقط ثبت می‌شود تا پیگیری شود.
                    try {
                        $this->invoiceGenerator->generateCancellationDocs(
                            $locked,
                            withRefundReceipt: (bool) $refundWallet
                        );
                    } catch (Throwable $e) {
                        Log::channel('financial')->critical('Cancellation documents were not generated for a cancelled order', [
                            'order_id' => $locked->id,
                            'order_number' => $locked->order_number,
                            'with_refund_receipt' => (bool) $refundWallet,
                            'error' => $e->getMessage(),
                        ]);
                    }
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
