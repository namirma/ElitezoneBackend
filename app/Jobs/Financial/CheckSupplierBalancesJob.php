<?php

namespace App\Jobs\Financial;

use App\Constants\FinancialCodes;
use App\Jobs\Notification\SendSmsJob;
use App\Models\Order;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Financial\DebtEngine;
use App\Services\Financial\WalletTransactionEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Monitor supplier wallets and release buyer refund holds when a supplier
 * that owed a weighbridge-deficit debt has topped up their wallet.
 *
 * Context — deficit weighbridge flow (phase 8):
 *   If at weighbridge time the supplier's wallet cannot cover (PTS − PTSM),
 *   SupplierDebtHandler moves **no money at all**. It only records what is
 *   owed in each direction: `supplier_debt_amount` (owed by the supplier) and
 *   `buyer_refund_pending_amount` (owed to the buyer).
 *
 *   The platform deliberately does not front the buyer's refund — management
 *   decision, round-2 question 2. It used to, and because the Saze wallet
 *   holds only the commission after payment, that debit failed and rolled the
 *   whole weighbridge back: with a genuinely drained supplier wallet no
 *   under-delivery could be recorded at all.
 *
 *   This job runs periodically and, for each such order:
 *     - Checks the supplier wallet balance
 *     - If balance >= supplier_debt_amount: reclaims the debt (WS → WSSG) and
 *       only then pays the buyer (WSSG → WO), so the money always exists
 *       before it moves. Both legs sit in one transaction.
 */
class CheckSupplierBalancesJob implements ShouldBeUnique, ShouldQueue
{
    /**
     * جابِ زمان‌بندی‌شدهٔ singleton — قفلِ سراسری تا دو نمونه (به‌دلیل backlog صف یا
     * cadenceِ هر-دقیقه) هم‌زمان اجرا نشوند. ۱۰ دقیقه سقفِ نگه‌داریِ قفل است.
     */
    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'check-supplier-balances';
    }

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * اگر بررسی موجودی سوپلایرها با خطا مواجه شد، یک بار دیگر بعد از ۲ دقیقه تلاش می‌کند.
     * این job در Kernel::schedule() زمان‌بندی شده و اجرای مجدد از دست نمی‌رود.
     */
    public int $tries = 2;

    public array $backoff = [120];

    public function handle(WalletTransactionEngine $walletEngine, DebtEngine $debtEngine): void
    {
        // chunkById() processes one batch at a time (memory-safe at scale) while
        // still supporting eager loading — unlike cursor() which can't eager-load.
        Order::where(Order::SUPPLIER_DEBT_PENDING, true)
            ->with(['items.supplier.wallet'])
            ->chunkById(100, function ($pendingOrders) use ($walletEngine, $debtEngine) {
                foreach ($pendingOrders as $order) {
                    /** @var Order $order */
                    try {
                        $this->processOrder($order, $walletEngine, $debtEngine);
                    } catch (\Exception $e) {
                        Log::channel('financial')->error("CheckSupplierBalancesJob error for order {$order->id}: ".$e->getMessage());
                    }
                }
            });
    }

    private function processOrder(Order $order, WalletTransactionEngine $walletEngine, DebtEngine $debtEngine): void
    {
        $firstItem = $order->items->first();
        if (! $firstItem) {
            return;
        }

        $supplierUser = $firstItem->supplier;
        if (! $supplierUser || ! $supplierUser->wallet) {
            return;
        }

        $debtAmount = (float) $order->{Order::SUPPLIER_DEBT_AMOUNT};

        // مالیاتِ معلق (بند ۸.۴) عمداً ستونِ خودش را دارد و در بدهی جمع نمی‌شود:
        // بدهی به کیف پول سازه وصول می‌شود ولی مالیات باید در کیف پولِ خودِ
        // تأمین‌کننده هولد شود. یک سفارش می‌تواند فقط مالیاتِ معلق داشته باشد —
        // وقتی کسری‌ای در کار نبوده ولی موجودی برای هولد کم آمده.
        $vatPending = (float) ($order->{Order::SUPPLIER_VAT_PENDING_AMOUNT} ?? 0);

        if ($debtAmount <= 0 && $vatPending <= 0) {
            // Debt already cleared or zero — just unflag
            $order->forceFill([
                Order::SUPPLIER_DEBT_PENDING => false,
                Order::SUPPLIER_DEBT_AMOUNT => 0,
            ])->save();

            return;
        }

        // هر دو با هم سنجیده می‌شوند: اگر فقط طلب را چک کنیم ممکن است وصول شود
        // ولی هولدِ مالیات در همان تراکنش شکست بخورد و همه‌چیز rollback شود —
        // هر بار، بی‌سروصدا، تا ابد.
        if ((float) $supplierUser->wallet->balance < $debtAmount + $vatPending) {
            return; // Still insufficient — try again next run
        }

        // Supplier now has enough — process the reclaim and release the buyer hold
        DB::transaction(function () use ($order, $debtAmount, $vatPending, $supplierUser, $walletEngine, $debtEngine) {

            // Re-lock the order and supplier wallet
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->first();
            if (! $lockedOrder || ! $lockedOrder->{Order::SUPPLIER_DEBT_PENDING}) {
                return; // Already handled by a concurrent run
            }

            $supplierWallet = Wallet::where('user_id', $supplierUser->id)->lockForUpdate()->first();
            if (! $supplierWallet || (float) $supplierWallet->balance < $debtAmount + $vatPending) {
                return; // Balance dropped below threshold since the check — skip
            }

            // ۱ و ۲. وصول طلب از تأمین‌کننده و سپس پرداخت عودت به خریدار.
            //
            // larastan نتیجهٔ `lockForUpdate()->first()` را `Model` می‌بیند نه `Order`، پس
            // هر `->id`/`->order_number` یک «دسترسی به پراپرتیِ تعریف‌نشده» می‌شد و به
            // baseline می‌رفت. بعد از گاردِ بالا این تنگ‌سازی درست است — هم‌الگو با
            // `ProcessReservationCompletionAction`.
            /** @var Order $lockedOrder */
            if ($debtAmount > 0) {
                $this->settleDebtAndRefundBuyer($lockedOrder, $debtAmount, (int) $supplierUser->id, $walletEngine);

                // دفترِ بدهیِ سطحِ شخص هم بسته می‌شود، وگرنه گاردِ برداشت
                // (بند ۳.۱۲) این تأمین‌کننده را برای همیشه بدهکار می‌دید.
                $debtEngine->repay(
                    (int) $supplierUser->id,
                    $debtAmount,
                    $lockedOrder,
                    "تسویه کسری باسکول سفارش {$lockedOrder->order_number}",
                );
            }

            // ۳. مالیاتِ معلق حالا هولد می‌شود — در کیف پولِ **خودِ** تأمین‌کننده،
            //    نه در کیف پول سازه. پس از بند ۱ می‌آید چون آن بند تازه از همین
            //    کیف پول برداشت کرده و ترتیب برعکس می‌توانست موجودی را کم بیاورد.
            if ($vatPending > 0) {
                $walletEngine->placeHold(
                    (int) $supplierUser->id,
                    $vatPending,
                    $lockedOrder,
                    "مالیات امانی سفارش {$lockedOrder->order_number} — تا ثبت فاکتور مودیان"
                );
            }

            // 4. Clear the pending debt flags on the order
            $lockedOrder->forceFill([
                Order::SUPPLIER_DEBT_PENDING => false,
                Order::SUPPLIER_DEBT_AMOUNT => 0,
                Order::BUYER_REFUND_PENDING_AMOUNT => null,
                Order::SUPPLIER_VAT_PENDING_AMOUNT => null,
            ])->save();

            Log::channel('financial')->info(
                "Supplier debt cleared for order {$lockedOrder->id}. Debt: {$debtAmount}, suspended VAT: {$vatPending}"
            );
        });
    }

    /**
     * بند ۱ و ۲ بند ۸.۴: اول طلب از تأمین‌کننده وصول می‌شود، بعد عودت به خریدار
     * پرداخت می‌گردد — به همین ترتیب، تا کیف پول سازه هرگز منفی نشود.
     *
     * Must run inside the caller's transaction, with the order already locked.
     */
    private function settleDebtAndRefundBuyer(
        Order $lockedOrder,
        float $debtAmount,
        int $supplierId,
        WalletTransactionEngine $walletEngine,
    ): void {
        $reclaimDescription = __('wallet.descriptions.supplier_debt_reclaim', [
            'order_number' => $lockedOrder->order_number,
        ]);

        $walletEngine->debitSupplier(
            $lockedOrder, $debtAmount, FinancialCodes::STATUS_AVAILABLE, $supplierId, $reclaimDescription
        );
        $walletEngine->creditSystem(
            $lockedOrder, $debtAmount, FinancialCodes::STATUS_AVAILABLE, $reclaimDescription
        );

        // ۲. عودتِ خریدار **حالا** پرداخت می‌شود: WSSG(−) → WO(+).
        //
        // تا فاز ۸ این مبلغ در لحظه‌ی باسکول از کیف پول سازه جلو انداخته و
        // در هولدِ خریدار پارک می‌شد، و اینجا فقط هولد آزاد می‌گشت. ولی کیف
        // پول سازه آن موقع پولش را ندارد، پس آن مسیر می‌شکست و کلِ باسکول
        // rollback می‌شد. حالا هیچ پولی پیش از وصول جابه‌جا نمی‌شود: بند ۱
        // بالا تازه طلب را از تأمین‌کننده گرفته و به سازه ریخته، پس همین
        // الان پولِ پرداخت به خریدار موجود است.
        $refundAmount = (float) ($lockedOrder->{Order::BUYER_REFUND_PENDING_AMOUNT} ?? 0);

        // کیف پولِ پرداخت‌کننده و نه ثبت‌کننده (فاز ۶) — عودت به همان کیفی
        // برمی‌گردد که پول از آن رفته است.
        $buyerWallet = Wallet::where('user_id', $lockedOrder->payerUserId())->lockForUpdate()->first();

        if (! $buyerWallet) {
            throw new \RuntimeException(
                "Order {$lockedOrder->id}: buyer wallet missing — parked refund cannot be paid."
            );
        }

        $refundDescription = __('wallet.descriptions.buyer_refund_released', [
            'order_number' => $lockedOrder->order_number,
        ]);

        /** @var Wallet $buyerWallet */
        $legacyHold = $this->outstandingRefundHold($buyerWallet, (int) $lockedOrder->id);

        if ($legacyHold) {
            // سفارش‌هایی که **پیش از فاز ۸** پارک شده‌اند واقعاً یک هولد در کیف
            // پول خریدار دارند: آن موقع سازه پول را جلو انداخته بود. برای آن‌ها
            // مسیر درست همان آزادسازیِ هولد است — اگر فقط مسیر تازه را اجرا
            // می‌کردیم، آن پول برای همیشه در هولد می‌ماند و یک بار دیگر هم از
            // سازه پرداخت می‌شد.
            $walletEngine->releaseHold(
                $lockedOrder->payerUserId(),
                (float) $legacyHold->amount,
                $lockedOrder,
                $refundDescription,
                $legacyHold,
            );
            $refundAmount = (float) $legacyHold->amount;
        } elseif ($refundAmount <= 0) {
            // نه هولدِ قدیمی، نه مبلغِ ثبت‌شده — یعنی ردیف خراب است: پرچمِ
            // بدهی هست ولی هیچ‌جا ننوشته چقدر به خریدار بدهکاریم.
            //
            // **باید پرتاب کند، نه بی‌صدا رد شود.** اگر ادامه می‌داد، مرحله‌ی
            // ۳ پرچم را پاک می‌کرد در حالی که طلب از تأمین‌کننده وصول شده —
            // پس پول به سازه می‌رسید، پرچم بسته می‌شد، و خریدار برای همیشه
            // چیزی نمی‌گرفت بدون هیچ رویدادی که بعداً اصلاحش کند.
            throw new \RuntimeException(
                "Order {$lockedOrder->id}: supplier debt is pending but no buyer refund amount or hold was found."
            );
        } else {
            $walletEngine->debitSystem(
                $lockedOrder, $refundAmount, FinancialCodes::STATUS_AVAILABLE, $refundDescription
            );
            $walletEngine->creditBuyer(
                $lockedOrder, $refundAmount, FinancialCodes::STATUS_AVAILABLE, $refundDescription
            );
        }

        // Notify buyer — dispatched AFTER the transaction commits (afterCommit)
        // so the blocking SMS HTTP call never runs while this job holds the
        // order + both wallet row locks, and no "refund released" SMS is sent
        // if the transaction later rolls back.
        $buyer = $buyerWallet->user;
        if ($buyer?->mobile && $refundAmount > 0) {
            SendSmsJob::dispatch(
                $buyer->mobile,
                __('sms.buyer_refund_released', [
                    'amount' => number_format((int) $refundAmount),
                    'order_number' => $lockedOrder->order_number,
                ])
            )->afterCommit();
        }
    }

    /**
     * ردیفِ هولدِ عودت که هنوز آزاد نشده — با **حذفِ** آن‌هایی که قبلاً آزاد شده‌اند،
     * نه با تغییرِ وضعیتِ خودشان.
     *
     * ⚠️ اینجا عمداً `status` هیچ ردیفی عوض نمی‌شود — و این تنها راهِ درست است.
     *
     * قبلاً بعد از `releaseHold()` یک `$holdTx->update(['status' => AVAILABLE])`
     * هم اجرا می‌شد تا ردیف دیگر «هولدِ باز» شمرده نشود. ولی `LedgerReconciler`
     * موجودی را از روی همین ستون‌ها **بازپخش** می‌کند و قاعده‌اش این است:
     *
     *   deposit + hold      → balance ۰   ، hold +مبلغ
     *   deposit + confirmed → balance +مبلغ، hold ۰
     *   hold_release        → balance +مبلغ، hold −مبلغ
     *
     * پس با آن `update`، ردیفِ اصلی از سطرِ اول به سطرِ دوم می‌پرید و بازپخش
     * `balance = +۲×مبلغ` و `hold = −مبلغ` می‌داد، در حالی که کیف واقعی
     * `+۱×مبلغ` و `۰` دارد. یعنی **هر آزادسازیِ بدهیِ سوپلایر، کیف پول خریدار را
     * برای همیشه در `financial:doctor` مغایر می‌کرد** — به اندازهٔ کلِ مبلغِ عودت،
     * در هر دو ستون. ردیفِ دفتر یک *رویدادِ گذشته* است؛ آن رویداد واقعاً «قرار
     * دادنِ هولد» بود و بازنویسی‌اش تاریخ را عوض می‌کند.
     *
     * «آزاد شده یا نه» از خودِ دفتر خوانده می‌شود: `releaseHold()` در
     * `meta.source_hold_id` به ردیفِ مبدأ اشاره می‌کند. (`orphanHolds()` هم
     * مستقلاً درست کار می‌کند، چون +hold و −hold_release را با هم جمع می‌زند.)
     */
    private function outstandingRefundHold(Wallet $buyerWallet, int $orderId): ?WalletTransaction
    {
        $releasedHoldIds = $buyerWallet->transactions()
            ->where('order_id', $orderId)
            ->where('type', WalletTransaction::TYPE_HOLD_RELEASE)
            ->get(['id', 'meta'])
            ->map(fn (WalletTransaction $tx) => is_array($tx->meta) ? ($tx->meta['source_hold_id'] ?? null) : null)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        return $buyerWallet->transactions()
            ->where('order_id', $orderId)
            ->where('type', WalletTransaction::TYPE_DEPOSIT)
            ->where('status', FinancialCodes::STATUS_HOLD)
            ->whereNotIn('id', $releasedHoldIds)
            ->latest()
            ->first();
    }
}
