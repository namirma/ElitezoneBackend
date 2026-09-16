<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Constants\FinancialCodes;
use App\Jobs\Notification\SendSmsJob;
use App\Models\DebtEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the deficit leg of a weighbridge result.
 *
 * Called only when POOM < POO (under-delivery). There are two outcomes:
 *   - Supplier covers the delta immediately → buyer refund lands as AVAILABLE.
 *   - Supplier cannot cover now → buyer refund is parked in HOLD and the order
 *     is flagged for CheckSupplierBalancesJob to release later.
 *
 * Must be called inside an active DB::transaction with a locked Order.
 * Returns the status string for the orchestrator response.
 */
class SupplierDebtHandler
{
    public function __construct(
        protected WalletTransactionEngine $walletEngine,
        protected DebtEngine $debtEngine,
    ) {}

    /**
     * @return 'deficit'|'deficit_pending_supplier'
     */
    public function handle(Order $order, float $refundAmount, float $reclaimAmount): string
    {
        $supplierId = (int) $order->items->first()->supplier_user_id;

        // Pessimistic lock on the supplier wallet to avoid a race with other jobs.
        $supplierWallet = Wallet::where('user_id', $supplierId)->lockForUpdate()->first();
        $supplierCanCover = $supplierWallet && ((float) $supplierWallet->balance >= $reclaimAmount);

        if ($supplierCanCover) {
            return $this->settleImmediately($order, $refundAmount, $reclaimAmount, $supplierId);
        }

        return $this->parkDebtAndNotify($order, $refundAmount, $reclaimAmount);
    }

    /**
     * ⚠️ ترتیب legها باربر است — تغییرش ندهید.
     *
     * پول باید **سوپلایر ← سازه ← خریدار** حرکت کند، به همین ترتیب.
     *
     * قبلاً `debitSystem($refundAmount)` بالای `handle()` و پیش از هر انشعابی صدا
     * زده می‌شد، یعنی سازه اول پول می‌داد و بعد از سوپلایر پس می‌گرفت. ولی سازه در
     * لحظه‌ی پرداخت POO را گرفته و بلافاصله PTS را پرداخته، پس تنها چیزی که نگه
     * می‌دارد کمیسیون است (`POO − PTS`) — مبلغی کوچک‌تر از عودتِ کسری در حالت
     * معمول. نتیجه: `InsufficientBalanceException` و rollback شدنِ **کلِ باسکول**،
     * یعنی هیچ کم‌تحویلی‌ای در سیستم قابل ثبت نبود؛ آن هم با پیام
     * «موجودی کیف پول کافی نیست» که انگار مشکلِ خریدار است.
     *
     * (تست `ProcessWeighbridgeResultActionTest` این را نمی‌گرفت چون کیف پول سازه را
     *  دستی شارژ می‌کرد — همان کاری که در محیط واقعی هیچ‌کس نمی‌کند.)
     *
     * همین قاعده در مسیر کوپن هم مستند است: هیچ legی نباید WSSG را منفی کند.
     */
    private function settleImmediately(
        Order $order,
        float $refundAmount,
        float $reclaimAmount,
        int $supplierId,
    ): string {
        // ۱) اول از سوپلایر پس بگیر — منبعِ واقعیِ این پول، اضافه‌دریافتیِ اوست.
        $this->walletEngine->debitSupplier(
            $order, $reclaimAmount, FinancialCodes::STATUS_AVAILABLE, $supplierId, 'کسر بابت کسری باسکول'
        );
        $this->walletEngine->creditSystem(
            $order, $reclaimAmount, FinancialCodes::STATUS_AVAILABLE,
            "بازپس‌گیری از تامین‌کننده بابت کسری باسکول سفارش {$order->order_number}"
        );

        // ۲) حالا که سازه تأمین شده، به خریدار برگردان. مابه‌التفاوتِ
        //    `refundAmount − reclaimAmount` همان کمیسیونی است که سازه باید از
        //    سهم خودش برگرداند و در لحظه‌ی پرداخت نگه داشته است.
        $this->walletEngine->debitSystem(
            $order, $refundAmount, FinancialCodes::STATUS_AVAILABLE,
            "پرداخت وجه کسری باسکول سفارش {$order->order_number} به خریدار"
        );
        $this->walletEngine->creditBuyer(
            $order, $refundAmount, FinancialCodes::STATUS_AVAILABLE, 'بازگشت وجه ناشی از کسری باسکول'
        );

        return 'deficit';
    }

    private function parkDebtAndNotify(
        Order $order,
        float $refundAmount,
        float $reclaimAmount,
    ): string {
        // **هیچ پولی اینجا جابه‌جا نمی‌شود** (تصمیم مدیریت، دور ۲ پرسش ۲/ب).
        //
        // پیش از این سازه مبلغ عودت را جلو می‌انداخت (`debitSystem`) و به هولدِ
        // خریدار می‌ریخت. ولی کیف پول سازه پس از پرداخت فقط کمیسیون را دارد و
        // عودت از آن بزرگ‌تر است، پس آن برداشت می‌شکست و کلِ تراکنشِ باسکول —
        // از جمله همین پارک — rollback می‌شد. نتیجه: با کیف پولِ واقعاً خالیِ
        // تأمین‌کننده **هیچ کسری‌ای قابل ثبت نبود**.
        //
        // حالا فقط دو عدد ثبت می‌شود: طلب از تأمین‌کننده و بدهی به خریدار.
        // پولی که وجود ندارد منتقل هم نمی‌شود، پس دفتر متوازن می‌ماند و
        // `CheckSupplierBalancesJob` با شارژِ بعدی هر دو لِگ را با هم انجام
        // می‌دهد.
        $order->forceFill([
            Order::SUPPLIER_DEBT_PENDING => true,
            Order::SUPPLIER_DEBT_AMOUNT => $reclaimAmount,
            Order::BUYER_REFUND_PENDING_AMOUNT => $refundAmount,
        ])->save();

        // دفترِ بدهیِ سطحِ **شخص** (فاز ۹). ستون‌های بالا per-order‌اند و به
        // تنهایی نمی‌گویند «این تأمین‌کننده رویِ هم چقدر بدهکار است» — همان
        // چیزی که گاردِ برداشت به آن تکیه می‌کند.
        // متغیرِ تایپ‌دار و نه `?->` روی چپِ `??` — larastan روی این شاخه آیتم را
        // non-nullable می‌بیند و آن ترکیب را «بی‌مورد» گزارش می‌کند.
        $firstItem = $order->items->first();
        /** @var OrderItem|null $firstItem */
        $supplierId = $firstItem ? (int) $firstItem->supplier_user_id : 0;

        if ($supplierId > 0) {
            $this->debtEngine->incur(
                $supplierId,
                $reclaimAmount,
                DebtEntry::TYPE_WEIGHBRIDGE_DEFICIT,
                $order,
                "کسری باسکول سفارش {$order->order_number}",
            );
        }

        // Queue the SMS and defer it until AFTER the surrounding weighbridge transaction
        // commits. The previous synchronous SmsService::send() was a blocking HTTP POST
        // executed while the order + wallet rows were lockForUpdate-held — a slow TSMS
        // endpoint would hold those locks for the whole network round-trip, and a later
        // rollback would have already told the supplier about a debt that never landed.
        $supplierUser = $order->items->first()?->supplier;
        if ($supplierUser?->mobile) {
            SendSmsJob::template($supplierUser->mobile, 'supplier_debt_notification', [
                'order_number' => $order->order_number,
                'amount' => number_format((int) $reclaimAmount),
            ])->afterCommit();
        }

        Log::channel('financial')->warning(
            "Supplier {$supplierUser?->id} insufficient balance for deficit reclaim on order {$order->id}. Debt: {$reclaimAmount}"
        );

        return 'deficit_pending_supplier';
    }

    /**
     * تسویه‌ی کسریِ پارک‌شده‌ی یک سفارش — قرینه‌ی `parkDebtAndNotify`.
     *
     * ⚠️ **تنها مسیرِ تسویه‌ی `weighbridge_deficit`، با دو فراخوان:**
     * `CheckSupplierBalancesJob` (پس از شارژِ کیف پول) و `RepayUserDebtAction`
     * (تأمین‌کننده خودش از تبِ بدهی می‌پردازد). پیش از این، `/profile/debts/repay`
     * لِگ‌های عمومی را می‌زد و پرچم‌های سفارش را دست نمی‌زد؛ کران بعداً همان طلب
     * را **دوباره** از تأمین‌کننده برمی‌داشت (`repay()` آن‌جا به صفر clamp می‌شد و
     * کسی نمی‌دید) — بدهیِ ۲ میلیونی ۴ میلیون خرج داشت. کپیِ این بدنه در دو جا
     * یعنی روزی یکی عوض شود و همان باگ برگردد.
     *
     * خودش تراکنش و قفل می‌گیرد: سفارش → کیف پولِ تأمین‌کننده → کاربر.
     *
     * Context — the refund is paid only AFTER the reclaim (WS → WSSG → WO), so the
     * money always exists before it moves; the platform deliberately does not
     * front the buyer's refund (management decision, round-2 question 2).
     *
     * @return bool true = پرچم بسته شد؛ false = هنوز نه (موجودیِ ناکافی، پرچمی
     *              نمانده، یا بدهیِ دفتر دیگر باز نیست) و فراخوان تصمیم می‌گیرد.
     */
    public function settlePending(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            /** @var Order|null $locked */
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked || ! $locked->{Order::SUPPLIER_DEBT_PENDING}) {
                return false; // Already handled by a concurrent run
            }

            $debtAmount = (float) $locked->{Order::SUPPLIER_DEBT_AMOUNT};

            // مالیاتِ معلق (بند ۸.۴) عمداً ستونِ خودش را دارد و در بدهی جمع نمی‌شود:
            // بدهی به کیف پول سازه وصول می‌شود ولی مالیات باید در کیف پولِ خودِ
            // تأمین‌کننده هولد شود. یک سفارش می‌تواند فقط مالیاتِ معلق داشته باشد —
            // وقتی کسری‌ای در کار نبوده ولی موجودی برای هولد کم آمده.
            $vatPending = (float) ($locked->{Order::SUPPLIER_VAT_PENDING_AMOUNT} ?? 0);

            if ($debtAmount <= 0 && $vatPending <= 0) {
                // Debt already cleared or zero — just unflag
                $locked->forceFill([
                    Order::SUPPLIER_DEBT_PENDING => false,
                    Order::SUPPLIER_DEBT_AMOUNT => 0,
                ])->save();

                return true;
            }

            if ($debtAmount > 0) {
                $supplierId = (int) $locked->items()->value(OrderItem::SUPPLIER_USER_ID);

                // فقط خودِ طلب سنجیده می‌شود، نه طلب + مالیات. حالا که مالیات هولد
                // نمی‌شود (تصمیم مدیریت ۲۰۲۶-۰۹-۰۹، بندِ ۶)، افزودنِ `$vatPending` به
                // این آستانه یک **باگ** بود: تأمین‌کننده‌ای که دقیقاً به‌اندازه‌ی طلب
                // پول داشت، تا ابد رد می‌شد.
                $supplierWallet = $supplierId > 0
                    ? Wallet::where(Wallet::USER_ID, $supplierId)->lockForUpdate()->first()
                    : null;

                if (! $supplierWallet || (float) $supplierWallet->balance < $debtAmount) {
                    return false; // Still insufficient — try again next run
                }

                // دفاعِ دوم: پرچمِ سفارش و دفترِ بدهی دو ثبتِ جدا از یک طلب‌اند. اگر
                // دفتر از راهِ دیگری بسته شده باشد (بازپرداختِ عمومیِ پیش از این
                // اصلاح)، وصولِ دوباره یعنی همان پول دو بار — `repay()` پایین فقط
                // بی‌صدا صفر برمی‌گرداند. پس رد و لاگ، نه وصول.
                $outstanding = $this->debtEngine->outstandingForOrder($supplierId, (int) $locked->id);

                if (Money::lt($outstanding, $debtAmount)) {
                    Log::channel('financial')->error(
                        "Order {$locked->id}: supplier_debt_pending is set for {$debtAmount} but only {$outstanding} "
                        .'is outstanding on the debt ledger — reclaim skipped, needs manual review.'
                    );

                    return false;
                }

                // ۱ و ۲. وصول طلب از تأمین‌کننده و سپس پرداخت عودت به خریدار.
                $this->settleDebtAndRefundBuyer($locked, $debtAmount, $supplierId);

                // دفترِ بدهیِ سطحِ شخص هم بسته می‌شود، وگرنه گاردِ برداشت
                // (بند ۳.۱۲) این تأمین‌کننده را برای همیشه بدهکار می‌دید.
                $this->debtEngine->repay(
                    $supplierId,
                    $debtAmount,
                    $locked,
                    "تسویه کسری باسکول سفارش {$locked->order_number}",
                );
            }

            // ۳. **دیگر هولدی گذاشته نمی‌شود** — تصمیم مدیریت ۲۰۲۶-۰۹-۰۹، بندِ ۶:
            //    مالیاتِ تأمین‌کننده «اصلاً نباید مسدود شود».
            //
            //    ستونِ `SUPPLIER_VAT_PENDING_AMOUNT` روی سفارش‌های **قدیمی** هنوز
            //    مقدار دارد. آن عدد هرگز از کیف پول برداشته نشده بود — فقط ثبت
            //    شده بود که «این‌قدر باید بعداً هولد شود». حالا که هولدی در کار
            //    نیست، درست‌ترین کار پاک‌کردنِ همان یادداشت است (بندِ ۴ پایین)،
            //    نه برداشتِ پول از تأمین‌کننده.
            //
            //    ⚠️ پس عمداً هیچ تراکنشِ مالی‌ای این‌جا اجرا نمی‌شود.

            // 4. Clear the pending debt flags on the order
            $locked->forceFill([
                Order::SUPPLIER_DEBT_PENDING => false,
                Order::SUPPLIER_DEBT_AMOUNT => 0,
                Order::BUYER_REFUND_PENDING_AMOUNT => null,
                Order::SUPPLIER_VAT_PENDING_AMOUNT => null,
            ])->save();

            Log::channel('financial')->info(
                "Supplier debt cleared for order {$locked->id}. Debt: {$debtAmount}, suspended VAT: {$vatPending}"
            );

            return true;
        });
    }

    /**
     * بند ۱ و ۲ بند ۸.۴: اول طلب از تأمین‌کننده وصول می‌شود، بعد عودت به خریدار
     * پرداخت می‌گردد — به همین ترتیب، تا کیف پول سازه هرگز منفی نشود.
     *
     * Must run inside the caller's transaction, with the order already locked.
     */
    private function settleDebtAndRefundBuyer(Order $lockedOrder, float $debtAmount, int $supplierId): void
    {
        $reclaimDescription = __('wallet.descriptions.supplier_debt_reclaim', [
            'order_number' => $lockedOrder->order_number,
        ]);

        $this->walletEngine->debitSupplier(
            $lockedOrder, $debtAmount, FinancialCodes::STATUS_AVAILABLE, $supplierId, $reclaimDescription
        );
        $this->walletEngine->creditSystem(
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
            $this->walletEngine->releaseHold(
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
            $this->walletEngine->debitSystem(
                $lockedOrder, $refundAmount, FinancialCodes::STATUS_AVAILABLE, $refundDescription
            );
            $this->walletEngine->creditBuyer(
                $lockedOrder, $refundAmount, FinancialCodes::STATUS_AVAILABLE, $refundDescription
            );
        }

        // Notify buyer — dispatched AFTER the transaction commits (afterCommit)
        // so the blocking SMS HTTP call never runs while this holds the
        // order + both wallet row locks, and no "refund released" SMS is sent
        // if the transaction later rolls back.
        // کیف پول بدون کاربر وجود ندارد (کلید خارجی)، پس `?->` اینجا چیزی نمی‌گرفت.
        if ($buyerWallet->user->mobile && $refundAmount > 0) {
            SendSmsJob::template($buyerWallet->user->mobile, 'buyer_refund_released', [
                'amount' => number_format((int) $refundAmount),
                'order_number' => $lockedOrder->order_number,
            ])->afterCommit();
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
