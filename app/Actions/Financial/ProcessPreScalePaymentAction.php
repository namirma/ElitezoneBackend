<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Constants\FinancialCodes;
use App\Events\Order\OrderPaid;
use App\Models\Order;
use App\Models\SupplierProfile;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Financial\WalletTransactionEngine;
use App\Services\Invoice\InvoiceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessPreScalePaymentAction
{
    public function __construct(
        protected SettlementCalculatorService $calculator,
        protected WalletTransactionEngine $walletEngine,
        protected InvoiceGenerator $invoiceGenerator,
    ) {}

    public function execute(Order $order): void
    {
        if ($this->isAlreadyProcessed($order)) {
            Log::channel('financial')->warning("ProcessPreScalePaymentAction skipped: order {$order->id} already paid.");

            return;
        }

        DB::transaction(function () use ($order) {

            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \RuntimeException(__('orders.internal_order_not_found'));
            }

            // larastan نتیجهٔ `lockForUpdate()->first()` را `Model` می‌بیند، نه `Order`؛
            // بعد از گاردِ بالا این تنگ‌سازی درست است و `resolveVatRate(Order)` را
            // بدون افزودن ورودی به baseline قابل فراخوانی می‌کند.
            /** @var Order $locked */
            if ($this->isAlreadyProcessed($locked)) {
                Log::channel('financial')->warning("Race detected: order {$locked->id} already paid.");

                return;
            }

            $locked->load('items', 'eliteYAR.badge');

            $firstItem = $locked->items->first();

            if (! $firstItem) {
                Log::channel('financial')->error("Order {$locked->id} has no items.");
                throw new \RuntimeException(__('orders.items_missing_for_payment'));
            }

            $officialPrice = (float) $locked->getAttribute(Order::OFFICIAL_PRICE);
            if ($officialPrice <= 0) {
                $officialPrice = (float) $firstItem->unit_price;
            }

            $weightBeforeScale = (float) ($locked->getAttribute(Order::WEIGHT_BEFORE_SCALE) ?? 0);
            $shippingCost = (float) ($locked->shipping_cost ?? 0);
            $vatRate = (float) ($locked->vat_rate ?? 10.0);

            // نرخ کمیسیون بر اساس نشانِ الیت‌یارِ صاحب کمیسیون (elite_yar_user_id) خوانده می‌شود،
            // نه beneficiary (گیرندهٔ کالا). ProcessWeighbridgeResultAction هم از همین eliteYAR.badge
            // استفاده می‌کند؛ خواندن از beneficiary باعث ناسازگاری نرخ بین pre-scale و باسکول می‌شد.
            $badgeCode = $locked->eliteYAR?->badge?->code ?? 'pioneer';
            $rates = $this->calculator->getCommissionRates($badgeCode);

            $ownerDiscountRate = $rates['ow_final_rate'];
            $sazeShareRate = $rates['ssg_final_rate'];
            $eliteYARRate = $rates['co_final_rate'];

            // ── Snapshot formula-variant flags from supplier contract ──────────
            $realSupplierId = $firstItem->supplier_user_id;
            $supplierProfile = SupplierProfile::where(SupplierProfile::USER_ID, $realSupplierId)->first();
            $transCommission = (bool) ($supplierProfile?->{SupplierProfile::TRANS_COMMISSION} ?? true);
            $transVat = (bool) ($supplierProfile?->{SupplierProfile::TRANS_VAT} ?? true);

            // ── Calculate POO and PTS using the correct formula variant ────────
            $POO = $this->calculator->calculatePOO(
                $shippingCost, $officialPrice, $weightBeforeScale,
                $ownerDiscountRate, $vatRate,
                $transCommission, $transVat
            );
            $PTS = $this->calculator->calculatePTS(
                $shippingCost, $officialPrice, $weightBeforeScale,
                $ownerDiscountRate, $sazeShareRate, $eliteYARRate, $vatRate,
                $transCommission, $transVat
            );

            // ── Snapshot all financial parameters onto the order ───────────────
            $locked->forceFill([
                Order::PAYMENT_OF_OWNER => $POO,
                Order::PAYMENT_TO_SUPPLIER => $PTS,
                Order::COMMISSION_RATE => 1.0,
                Order::VAT_RATE => $vatRate,
                Order::OWNER_DISCOUNT_RATE => $ownerDiscountRate,
                Order::TRANS_COMMISSION => $transCommission,
                Order::TRANS_VAT => $transVat,
            ])->save();

            // A + A.5 — buyer pays POO (WO→WSSG) و تخفیف کوپن (WSSG→WO).
            //
            // ترتیبِ چهار لِگ عمدی است: creditBuyer(تخفیف) *قبل* از debitBuyer(POO)
            // اجرا می‌شود تا خریدار فقط به «POO − تخفیف» موجودی نیاز داشته باشد؛
            // پیش از این، debit اول اجرا می‌شد و کاربری که دقیقاً مبلغِ
            // تخفیف‌خوردهٔ نمایش‌داده‌شده را داشت InsufficientBalanceException می‌گرفت.
            // متقابلاً debitSystem(تخفیف) *بعد* از creditSystem(POO) می‌آید تا کیف
            // پول سازه در هیچ لحظه‌ای منفی نشود (POO > تخفیف همیشه برقرار است).
            //
            // مبالغ، لِگ‌ها و شرحِ تراکنش‌ها تغییری نکرده‌اند: تخفیف همچنان یک
            // هزینهٔ بازاریابیِ صریح و قابل‌ممیزیِ سازه است و POO/PTS دست‌نخورده
            // می‌مانند — پس RefundOrderToWalletAction هم که همین لِگ را برعکس
            // می‌کند بدون تغییر درست کار می‌کند.
            $couponDiscount = min((float) ($locked->{Order::COUPON_DISCOUNT_AMOUNT} ?? 0), $POO);
            $couponDesc = null;

            if ($couponDiscount > 0) {
                $couponCode = $locked->coupon?->code ?? '-';
                $couponDesc = __('coupons.applied_description', ['code' => $couponCode, 'order' => $locked->order_number]);

                $this->walletEngine->creditBuyer($locked, $couponDiscount, FinancialCodes::STATUS_AVAILABLE, $couponDesc);
            }

            $this->walletEngine->debitBuyer(
                $locked, $POO, FinancialCodes::STATUS_AVAILABLE,
                "پرداخت وجه سفارش {$locked->order_number}"
            );
            $this->walletEngine->creditSystem(
                $locked, $POO, FinancialCodes::STATUS_AVAILABLE,
                "دریافت وجه از خریدار برای سفارش {$locked->order_number}"
            );

            if ($couponDiscount > 0) {
                $this->walletEngine->debitSystem($locked, $couponDiscount, FinancialCodes::STATUS_AVAILABLE, $couponDesc);
            }

            // B — Saze pays supplier PTS: WSSG(-) → WS(+)
            $this->walletEngine->debitSystem(
                $locked, $PTS, FinancialCodes::STATUS_AVAILABLE,
                "تخصیص سهم تامین‌کننده سفارش {$locked->order_number}"
            );
            $this->walletEngine->creditSupplier(
                $locked, $PTS, FinancialCodes::STATUS_AVAILABLE, $realSupplierId,
                "واریز سهم فروش سفارش {$locked->order_number}"
            );

            $locked->update([
                'status' => Order::STATUS_PROCESSING,
                'payment_status' => Order::PAYMENT_PAID,
                'paid_at' => now(),
            ]);

            // اعلانِ پرداخت — به خریدار و به تأمین‌کننده.
            //
            // تا پیش از این `OrderPaid` **فقط** از `PayOrderAction` dispatch می‌شد، و آن
            // اکشن هیچ فراخوانی‌ای ندارد (بقایای مسیرِ درگاهی که هرگز وصل نشد). یعنی
            // رویداد در هیچ مسیرِ زنده‌ای شلیک نمی‌شد و پیامکِ «سفارش شما ثبت شد» هرگز
            // برای کسی نمی‌رفت — نه خریدار، نه تأمین‌کننده.
            //
            // رویداد `ShouldDispatchAfterCommit` است، پس dispatch داخل تراکنش امن است:
            // تحویل تا commit صبر می‌کند و listener صف‌شده هرگز ردیفِ commit‌نشده نمی‌بیند.
            OrderPaid::dispatch($locked);

            // ── صدور اسناد مالی خرید مستقیم (A + B + C، با D در صورت داشتن الیت یار) ──
            // Doc D (گزارش الیت‌یار) فقط وقتی صادر می‌شود که یک الیت‌یارِ صاحبِ کمیسیون
            // روی سفارش باشد — یعنی elite_yar_user_id ست شده و با خودِ خریدار (placer)
            // یکی نباشد. این دقیقاً همان شرطی است که WeighbridgeVatRouter::routeEliteYAR
            // برای هولد کمیسیون استفاده می‌کند، پس سند با جریان پول هم‌راستا می‌ماند.
            // (قبلاً روی beneficiary_user_id گیت شده بود؛ در مسیر «کارفرما با الیت‌یار»
            //  که beneficiary=null است، Doc D اشتباهاً صادر نمی‌شد.)
            // مقایسه با پرداخت‌کننده و نه ثبت‌کننده (فاز ۶) — دقیقاً به همان دلیلی
            // که بالا نوشته شده: این شرط باید با `routeEliteYAR` یکی بماند، و آن
            // هم به `payerUserId()` منتقل شد. اگر این یکی عقب می‌ماند، در مسیرِ
            // «الیت‌یار برای کارفرما» کمیسیون پرداخت می‌شد ولی سند D صادر نمی‌شد.
            // متغیرِ جداگانه و نه `@var` روی خودِ `$locked`: larastan 3 نتیجه‌ی
            // `lockForUpdate()->first()` را `Model` می‌بیند، و عوض‌کردنِ تایپِ
            // `$locked` هم نال‌چکِ واقعیِ بالا را «همیشه غلط» نشان می‌داد و هم
            // شمارشِ ورودی‌های baseline را جابه‌جا می‌کرد.
            /** @var Order $payerSource */
            $payerSource = $locked;
            $eliteYARId = $locked->getAttribute(Order::ELITE_YAR_USER_ID);
            $hasCommissionedEliteYAR = $eliteYARId !== null
                && (int) $eliteYARId !== $payerSource->payerUserId();

            $this->invoiceGenerator->generateDirectPurchaseDocs(
                $locked,
                withEliteYAR: $hasCommissionedEliteYAR
            );

            Log::channel('financial')->info("پرداخت سفارش {$locked->id} با موفقیت انجام شد. فرمول: TC={$transCommission} VAT={$transVat}");
        }, 3);
    }

    private function isAlreadyProcessed(Order $order): bool
    {
        return $order->payment_status === Order::PAYMENT_PAID;
    }
}
