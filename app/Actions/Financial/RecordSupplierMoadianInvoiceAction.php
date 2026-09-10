<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Exceptions\InsufficientBalanceException;
use App\Models\Order;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Financial\WalletTransactionEngine;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Release the supplier's VAT hold after they issue their Moadian invoice — Doc O.
 *
 * At weighbridge time (ProcessWeighbridgeResultAction) the supplier's VAT is
 * computed from PTSM and held **inside the supplier's own wallet**:
 *   supplierVat = PTSM × (VAT% / (1 + VAT%))
 *   supplier balance(-) → supplier balance_hold(+)
 *
 * When the supplier issues Doc O (Moadian invoice: supplier → Saze), that hold
 * is released back to their available balance:
 *   supplier balance_hold(-) → supplier balance(+)
 *
 * The money is the supplier's throughout — it is only locked until they produce
 * the invoice (management decision, round-2 question 1). It used to be debited
 * into the separate WVAT escrow instead, and the release then moved it *within*
 * WVAT, so the supplier never actually got their VAT back.
 *
 * Idempotent: a second call for the same order is a no-op. A missing hold is
 * tolerated — it means the weighbridge could not collect it and parked it as
 * supplier debt instead.
 */
class RecordSupplierMoadianInvoiceAction
{
    public function __construct(
        private readonly WalletTransactionEngine $walletEngine,
        private readonly InvoiceGenerator $invoiceGenerator,
        private readonly SettlementCalculatorService $calculator,
    ) {}

    /**
     * @param  Order  $order  The order whose supplier VAT hold should be released.
     * @param  string  $moadianRef  Doc-O reference from the Moadian system.
     */
    public function execute(Order $order, string $moadianRef): void
    {
        // ── Idempotency guard ─────────────────────────────────────────────────
        if ($order->{Order::SUPPLIER_MOADIAN_REF} !== null) {
            Log::channel('financial')->info("RecordSupplierMoadianInvoiceAction skipped: order {$order->id} already has supplier_moadian_ref.");

            return;
        }

        // ── Pre-conditions ────────────────────────────────────────────────────
        if ($order->{Order::WEIGHING_COMPLETED_AT} === null) {
            throw new \RuntimeException(__('orders.errors.weighbridge_not_completed'));
        }

        // ── Derive the supplier VAT from the post-weighbridge PTSM ────────────
        // PTSM is stored as payment_to_supplier after the weighbridge action
        // updates it. The VAT portion embedded in PTSM is:
        //   supplierVat = PTSM × (vat% / 100) / (1 + vat% / 100)
        // Same bcmath split used at weighbridge time — releases exactly what was held.
        $ptsm = (float) $order->{Order::PAYMENT_TO_SUPPLIER};
        // مثل `ReleaseEliteYARHoldAction` — `??` روی ستونِ NOT NULL مرده بود و
        // نرخ صفر می‌شد. اینجا بدتر است: گاردِ `if ($supplierVat > 0)` پایین‌تر
        // باعث می‌شد **هیچ** آزاد نشود در حالی که `supplier_moadian_ref` مهر
        // می‌خورد و گاردِ idempotency هر تلاش دوباره‌ای را می‌بست.
        $vatRate = $this->calculator->resolveVatRate($order);
        $supplierVat = $this->calculator->extractVat($ptsm, $vatRate)['vat'];

        // صاحبِ کیف پولی که هولد رویش نشسته. روی ردیف‌های سفارش است، نه روی
        // خودِ سفارش — همان قاعده‌ای که `WALLET_SUPPLIER` همه‌جا دارد.
        $supplierId = (int) $order->items()->value('supplier_user_id');

        DB::transaction(function () use (
            $order, $moadianRef,
            $supplierVat, $supplierId
        ) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \RuntimeException(__('orders.internal_order_not_found'));
            }

            // Double-check idempotency inside the transaction
            if ($locked->{Order::SUPPLIER_MOADIAN_REF} !== null) {
                return;
            }

            // ⚠️ **این آزادسازی حالا فقط برای سفارش‌های قدیمی است.**
            //
            // از ۲۰۲۶-۰۹-۰۹ (تصمیم مدیریت، بندِ ۶) مالیاتِ تأمین‌کننده دیگر هولد
            // نمی‌شود، پس سفارش‌های تازه هیچ هولدی ندارند و `releaseHold` با
            // `InsufficientBalanceException` رد می‌شود — که پایین گرفته و لاگ
            // می‌شود، نه پرتاب.
            //
            // ولی سفارش‌هایی که **پیش از** آن تصمیم باسکول خورده‌اند، پولِ واقعی
            // در `balance_hold` دارند. اگر این فراخوانی حذف شود، آن پول برای
            // همیشه گیر می‌کند. پس عمداً می‌ماند تا آخرین سفارشِ قدیمی سند O
            // بگیرد.
            if ($supplierVat > 0) {
                // هولد در کیف پولِ خودِ تأمین‌کننده آزاد می‌شود (تصمیم مدیریت،
                // دور ۲ پرسش ۱). مسیر قبلی هولد را درونِ کیف پولِ امانی
                // جابه‌جا می‌کرد، پس پول هرگز به تأمین‌کننده نمی‌رسید.
                //
                // اگر موجودیِ هولد کافی نباشد یعنی باسکول نتوانسته بود آن را
                // بگیرد و مبلغ روی بدهی پارک شده — در آن حالت چیزی برای
                // آزادکردن نیست و ثبتِ فاکتور نباید شکست بخورد.
                // متغیرِ جداگانه چون larastan نتیجه‌ی `lockForUpdate()->first()` را
                // `Model` می‌بیند؛ تایپِ خودِ `$locked` را دست نمی‌زنیم تا شمارشِ
                // ورودی‌های baseline جابه‌جا نشود.
                /** @var Order $lockedOrder */
                $lockedOrder = $locked;

                try {
                    $this->walletEngine->releaseHold(
                        $supplierId,
                        $supplierVat,
                        $lockedOrder,
                        "آزادسازی مالیات امانی — فاکتور مودیان: {$moadianRef}"
                    );
                } catch (InsufficientBalanceException) {
                    Log::channel('financial')->warning(
                        "Order {$lockedOrder->id}: no supplier VAT hold to release — it was parked as debt at weighbridge."
                    );
                }
            }

            $locked->forceFill([
                Order::SUPPLIER_MOADIAN_REF => $moadianRef,
                Order::SUPPLIER_HOLD_RELEASED_AT => now(),
            ])->save();

            // Doc O — فاکتور مودیان سوپلایر
            $this->invoiceGenerator->generateSupplierMoadianInvoice($locked);

            Log::channel('financial')->info("Supplier VAT hold released for order {$locked->id}. Ref: {$moadianRef}. VAT: {$supplierVat}");
        }, 3);
    }
}
