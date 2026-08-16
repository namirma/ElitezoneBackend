<?php

namespace App\Jobs\Financial;

use App\Constants\FinancialCodes;
use App\Jobs\Notification\SendSmsJob;
use App\Models\Order;
use App\Models\Wallet;
use App\Models\WalletTransaction;
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
 * Context — deficit weighbridge flow:
 *   If at weighbridge time the supplier's wallet did not have enough balance
 *   to cover (PTS − PTSM), ProcessWeighbridgeResultAction:
 *     1. Sets order.supplier_debt_pending = true and records the owed amount
 *     2. Credits the buyer's wallet with the refund but in HOLD status
 *
 *   This job runs periodically and, for each such order:
 *     - Checks the supplier wallet balance
 *     - If balance >= supplier_debt_amount: reclaims the debt, releases the
 *       buyer hold to AVAILABLE, and notifies the buyer via SMS
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

    public function handle(WalletTransactionEngine $walletEngine): void
    {
        // chunkById() processes one batch at a time (memory-safe at scale) while
        // still supporting eager loading — unlike cursor() which can't eager-load.
        Order::where(Order::SUPPLIER_DEBT_PENDING, true)
            ->with(['items.supplier.wallet'])
            ->chunkById(100, function ($pendingOrders) use ($walletEngine) {
                foreach ($pendingOrders as $order) {
                    /** @var Order $order */
                    try {
                        $this->processOrder($order, $walletEngine);
                    } catch (\Exception $e) {
                        Log::channel('financial')->error("CheckSupplierBalancesJob error for order {$order->id}: ".$e->getMessage());
                    }
                }
            });
    }

    private function processOrder(Order $order, WalletTransactionEngine $walletEngine): void
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
        if ($debtAmount <= 0) {
            // Debt already cleared or zero — just unflag
            $order->forceFill([
                Order::SUPPLIER_DEBT_PENDING => false,
                Order::SUPPLIER_DEBT_AMOUNT => 0,
            ])->save();

            return;
        }

        if ((float) $supplierUser->wallet->balance < $debtAmount) {
            return; // Still insufficient — try again next run
        }

        // Supplier now has enough — process the reclaim and release the buyer hold
        DB::transaction(function () use ($order, $debtAmount, $supplierUser, $walletEngine) {

            // Re-lock the order and supplier wallet
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->first();
            if (! $lockedOrder || ! $lockedOrder->{Order::SUPPLIER_DEBT_PENDING}) {
                return; // Already handled by a concurrent run
            }

            $supplierWallet = Wallet::where('user_id', $supplierUser->id)->lockForUpdate()->first();
            if (! $supplierWallet || (float) $supplierWallet->balance < $debtAmount) {
                return; // Balance dropped below threshold since the check — skip
            }

            // 1. Reclaim the debt from the supplier into the system wallet: WS(−) → WSSG(+).
            //    Routed through WalletTransactionEngine so BOTH legs leave an auditable
            //    WalletTransaction (the previous raw increment/decrement left no trail).
            //
            // larastan نتیجهٔ `lockForUpdate()->first()` را `Model` می‌بیند نه `Order`، پس
            // هر `->id`/`->order_number` یک «دسترسی به پراپرتیِ تعریف‌نشده» می‌شد و به
            // baseline می‌رفت. بعد از گاردِ بالا این تنگ‌سازی درست است — هم‌الگو با
            // `ProcessReservationCompletionAction`.
            /** @var Order $lockedOrder */
            $reclaimDescription = __('wallet.descriptions.supplier_debt_reclaim', [
                'order_number' => $lockedOrder->order_number,
            ]);

            $walletEngine->debitSupplier(
                $lockedOrder, $debtAmount, FinancialCodes::STATUS_AVAILABLE, $supplierUser->id, $reclaimDescription
            );
            $walletEngine->creditSystem(
                $lockedOrder, $debtAmount, FinancialCodes::STATUS_AVAILABLE, $reclaimDescription
            );

            // 2. Release the buyer's parked refund: WO balance_hold(−) → WO balance(+)
            //
            // ⚠️ ناتوانی در آزادسازی باید **پرتاب کند**، نه بی‌صدا رد شود. قبلاً کل این
            //    بلوک داخل یک `if` بود و اگر هولد پیدا نمی‌شد، مرحلهٔ ۳ باز هم پرچم بدهی
            //    را پاک می‌کرد — یعنی پول از سوپلایر پس گرفته می‌شد، پرچم بسته می‌شد، و
            //    عودتِ خریدار **برای همیشه** در هولد می‌ماند بدون هیچ رویدادی که آزادش کند
            //    (دقیقاً همان چیزی که `LedgerReconciler::orphanHolds()` بعداً گزارش می‌دهد).
            //    با پرتاب، کل تراکنش برمی‌گردد: نه بازپس‌گیریِ نیمه‌کاره می‌ماند و نه پرچم
            //    پاک می‌شود، پس اجرای بعدی دوباره و تمیز تلاش می‌کند. `handle()` استثنا را
            //    per-order می‌گیرد، پس یک سفارشِ گیرکرده بقیه را متوقف نمی‌کند.
            // کیف پولِ پرداخت‌کننده و نه ثبت‌کننده (فاز ۶) — عودت باید به همان
            // کیفی برگردد که پول از آن رفته است.
            $buyerWallet = Wallet::where('user_id', $lockedOrder->payerUserId())->lockForUpdate()->first();

            if (! $buyerWallet) {
                throw new \RuntimeException(
                    "Order {$lockedOrder->id}: buyer wallet missing — parked refund cannot be released."
                );
            }

            /** @var Wallet $buyerWallet — همان دلیلِ بالا برای کیف قفل‌شده. */
            $holdTx = $this->outstandingRefundHold($buyerWallet, (int) $lockedOrder->id);

            if (! $holdTx) {
                throw new \RuntimeException(
                    "Order {$lockedOrder->id}: no outstanding buyer hold found for a pending supplier debt."
                );
            }

            // Move balance_hold → balance AND record an append-only
            // TYPE_HOLD_RELEASE ledger row (the previous code mutated the hold
            // row + bare increment/decrement, leaving no distinct release event).
            //
            // مبلغِ آزادسازی = مبلغِ همان ردیفِ هولد، نه `$debtAmount`. این دو
            // عمداً فرق دارند: هولد «عودتِ خریدار» است (POO − POOM) ولی بدهی
            // «بازپس‌گیری از سوپلایر» (PTS − PTSM) — و اولی بزرگ‌تر است، چون
            // سهم کمیسیون را هم شامل می‌شود.
            $walletEngine->releaseHold(
                $lockedOrder->payerUserId(),
                (float) $holdTx->amount,
                $lockedOrder,
                __('wallet.descriptions.buyer_refund_released', [
                    'order_number' => $lockedOrder->order_number,
                ]),
                $holdTx,
            );

            // Notify buyer — dispatched AFTER the transaction commits (afterCommit)
            // so the blocking SMS HTTP call never runs while this job holds the
            // order + both wallet row locks, and no "refund released" SMS is sent
            // if the transaction later rolls back.
            $buyer = $buyerWallet->user;
            if ($buyer?->mobile) {
                SendSmsJob::dispatch(
                    $buyer->mobile,
                    __('sms.buyer_refund_released', [
                        'amount' => number_format((int) $holdTx->amount),
                        'order_number' => $lockedOrder->order_number,
                    ])
                )->afterCommit();
            }

            // 3. Clear the pending debt flag on the order
            $lockedOrder->forceFill([
                Order::SUPPLIER_DEBT_PENDING => false,
                Order::SUPPLIER_DEBT_AMOUNT => 0,
            ])->save();

            Log::channel('financial')->info("Supplier debt cleared for order {$lockedOrder->id}. Debt: {$debtAmount}");
        });
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
