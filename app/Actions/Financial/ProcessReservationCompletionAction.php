<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Constants\FinancialCodes;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Order;
use App\Models\SupplierProfile;
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
            Log::channel('financial')->warning("ProcessReservationCompletionAction skipped: order {$order->id} already completed.");

            return;
        }

        Log::channel('financial')->info("شروع فرآیند تکمیل وجه برای سفارش رزرو شده {$order->id}");

        DB::transaction(function () use ($order) {

            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \RuntimeException(__('orders.internal_order_not_found'));
            }

            if ($this->isAlreadyProcessed($locked)) {
                Log::channel('financial')->warning("Race detected: order {$locked->id} already completed.");

                return;
            }

            $locked->load('items', 'eliteYAR.badge');

            $firstItem = $locked->items->first();

            if (! $firstItem) {
                Log::channel('financial')->error("Order {$locked->id} has no items.");
                throw new \RuntimeException(__('orders.items_missing_for_completion'));
            }

            $op = (float) ($locked->official_price ?: ($firstItem->unit_price ?? 0));
            $trans = (float) ($locked->shipping_cost ?: 0);
            // این مسیر با `?:` نوشته شده بود و درست کار می‌کرد، ولی نرخ ۱۰ را
            // هاردکد داشت — با تغییر نرخ در تنظیمات، تسویه از سبد خرید واگرا
            // می‌شد. حالا هر سه مسیر پرداخت از یک منبع می‌خوانند.
            $vat = $this->calculator->resolveVatRate($locked);

            // وزن snapshot باید معتبر باشد — fallback بی‌صدا به ۱ کیلوگرم یعنی POO/PTS
            // روی یک کیلو محاسبه می‌شد و رزرو با مبلغ ناچیز «تکمیل» می‌شد. داده‌ی خراب
            // باید متوقف کند، نه پول جابه‌جا کند.
            $wbs = (float) ($locked->weight_before_scale ?? 0);
            if ($wbs <= 0) {
                Log::channel('financial')->critical("Order {$locked->id}: weight_before_scale is missing/zero — completion aborted.");
                throw new \RuntimeException(__('orders.invalid_weight_snapshot'));
            }

            // نرخ کمیسیون از نشانِ الیت‌یارِ صاحب کمیسیون (elite_yar_user_id) خوانده می‌شود،
            // نه beneficiary (گیرندهٔ کالا) — هم‌راستا با ProcessWeighbridgeResultAction.
            $badgeCode = $locked->eliteYAR?->badge?->code ?? 'pioneer';
            $rates = $this->calculator->getCommissionRates($badgeCode);

            $ow = $rates['ow_final_rate'];
            $ssg = $rates['ssg_final_rate'];
            $co = $rates['co_final_rate'];

            // ── Snapshot formula-variant flags from supplier contract ──────────
            // Prefer the already-snapshotted value on the order (set during the
            // reservation payment step), but fall back to the supplier profile in
            // case the order pre-dates this feature.
            $realSupplierId = $firstItem->supplier_user_id;
            $transCommission = $locked->{Order::TRANS_COMMISSION}
                ?? (bool) (SupplierProfile::where(SupplierProfile::USER_ID, $realSupplierId)->value(SupplierProfile::TRANS_COMMISSION) ?? true);
            $transVat = $locked->{Order::TRANS_VAT}
                ?? (bool) (SupplierProfile::where(SupplierProfile::USER_ID, $realSupplierId)->value(SupplierProfile::TRANS_VAT) ?? true);

            $POO = $this->calculator->calculatePOO($trans, $op, $wbs, $ow, $vat, $transCommission, $transVat);
            $PTS = $this->calculator->calculatePTS($trans, $op, $wbs, $ow, $ssg, $co, $vat, $transCommission, $transVat);

            if ($POO <= 0 || $PTS <= 0) {
                Log::channel('financial')->critical("Invalid financial calculation for order {$locked->id}", compact('POO', 'PTS'));
                throw new \RuntimeException(__('orders.invalid_financial_calculation'));
            }

            $PTR = (float) $locked->payment_to_reserve;

            $remainingAmount = (float) bcsub((string) $POO, (string) $PTR, 4);

            $buyerId = $locked->placer_user_id;
            $realSupplierId = $firstItem->supplier_user_id;

            // Coupon discount leg (once, at the full-payment moment): WSSG(-) → WO(+).
            // Same pattern as ProcessPreScalePaymentAction — the buyer is charged the
            // formula-true POO and credited the discount so POO/PTS math stays untouched.
            $couponDiscount = min((float) ($locked->{Order::COUPON_DISCOUNT_AMOUNT} ?? 0), $POO);
            $couponDesc = null;

            if ($couponDiscount > 0) {
                $couponCode = $locked->coupon?->code ?? '-';
                $couponDesc = __('coupons.applied_description', ['code' => $couponCode, 'order' => $locked->order_number]);
            }

            // Guard: check buyer balance against the actual debit amount (POO - PTR),
            // not final_amount - PTR which the controller used as an approximation.
            //
            // تخفیف کوپن از آستانه کسر می‌شود: خریدار «POO − PTR» را می‌پردازد و
            // بلافاصله تخفیف را پس می‌گیرد، پس موجودیِ لازمِ واقعی
            // «POO − PTR − تخفیف» است. قبلاً گارد روی مبلغ بدونِ تخفیف بود و
            // کاربری که دقیقاً مبلغِ نمایش‌داده‌شده را داشت رد می‌شد.
            if ($remainingAmount > 0) {
                $requiredBalance = max(0.0, $remainingAmount - $couponDiscount);
                $buyerWallet = Wallet::where('user_id', $buyerId)->lockForUpdate()->first();
                if (! $buyerWallet || $buyerWallet->balance < $requiredBalance) {
                    throw new InsufficientBalanceException($requiredBalance, (float) ($buyerWallet?->balance ?? 0));
                }
            }

            Log::channel('financial')->info("Order {$locked->id}: POO={$POO}, PTR={$PTR}, Remaining={$remainingAmount}, Coupon={$couponDiscount}");

            // اعتبارِ تخفیف قبل از برداشت به کیف خریدار می‌نشیند تا کسرِ
            // «POO − PTR» با موجودیِ تخفیف‌خورده هم ممکن باشد.
            if ($couponDiscount > 0) {
                $this->walletEngine->creditBuyer($locked, $couponDiscount, FinancialCodes::STATUS_AVAILABLE, $couponDesc);
            }

            if ($remainingAmount > 0) {
                $this->walletEngine->debitBuyer($locked, $remainingAmount, FinancialCodes::STATUS_AVAILABLE, "تکمیل وجه سفارش {$locked->order_number}");
                $this->walletEngine->creditSystem($locked, $remainingAmount, FinancialCodes::STATUS_AVAILABLE, "دریافت وجه تکمیلی سفارش {$locked->order_number}");
            } elseif ($remainingAmount < 0) {
                $refundAmount = abs($remainingAmount);
                $this->walletEngine->debitSystem($locked, $refundAmount, FinancialCodes::STATUS_AVAILABLE, "بازگشت مابه‌التفاوت افت قیمت سفارش {$locked->order_number}");
                $this->walletEngine->creditBuyer($locked, $refundAmount, FinancialCodes::STATUS_AVAILABLE, 'دریافت مابه‌التفاوت افت قیمت رزرو');
            }

            // برداشتِ تخفیف از کیف سازه بعد از دریافتِ وجه تکمیلی، تا WSSG در
            // هیچ لحظه‌ای منفی نشود.
            if ($couponDiscount > 0) {
                $this->walletEngine->debitSystem($locked, $couponDiscount, FinancialCodes::STATUS_AVAILABLE, $couponDesc);
            }

            // Release the reservation hold on the Saze (WSSG) wallet (balance_hold → balance)
            // via the append-only releaseHold() so a distinct TYPE_HOLD_RELEASE ledger row is
            // written and the placement (ProcessReservationPaymentAction) and this release can
            // be reconciled as a netting pair — per the codebase audit contract. Never move a
            // hold with a bare debit-HOLD/credit-AVAILABLE pair.
            $systemUserId = (int) config('financial.system_user_id', 1);
            $this->walletEngine->releaseHold(
                $systemUserId,
                $PTR,
                $locked,
                "تبدیل موجودی هولد رزرو به قطعی — سفارش {$locked->order_number}",
            );

            $this->walletEngine->debitSystem($locked, $PTS, FinancialCodes::STATUS_AVAILABLE, "تخصیص سهم تامین‌کننده سفارش {$locked->order_number}");
            $this->walletEngine->creditSupplier($locked, $PTS, FinancialCodes::STATUS_AVAILABLE, $realSupplierId, "واریز سهم فروش سفارش {$locked->order_number}");

            $locked->forceFill([
                Order::STATUS => Order::STATUS_PROCESSING,
                Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
                Order::PAYMENT_OF_OWNER => $POO,
                Order::PAYMENT_TO_SUPPLIER => $PTS,
                Order::TRANS_COMMISSION => $transCommission,
                Order::TRANS_VAT => $transVat,
                Order::PAID_AT => now(),
            ])->save();

            // ── صدور اسناد تکمیل وجه (G + H + C) ────────────────────────
            $this->invoiceGenerator->generateReservationCompletionDocs($locked);

            Log::channel('financial')->info("تکمیل وجه سفارش {$locked->id} انجام شد.");
        }, 3);
    }

    private function isAlreadyProcessed(Order $order): bool
    {
        return $order->status === Order::STATUS_PROCESSING
            && $order->payment_status === Order::PAYMENT_PAID;
    }
}
