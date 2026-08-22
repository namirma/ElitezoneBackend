<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Constants\FinancialCodes;
use App\DataTransferObjects\Order\WeighbridgeReconcileResult;
use App\Models\DebtEntry;
use App\Models\Order;
use App\Models\Wallet;
use App\Services\Financial\DebtEngine;
use App\Services\Financial\SupplierDebtHandler;
use App\Services\Financial\WalletTransactionEngine;
use App\Services\Financial\WeighbridgeReconciler;
use App\Services\Financial\WeighbridgeVatRouter;
use App\Services\InventoryService;
use App\Services\Invoice\InvoiceGenerator;
use App\Services\Sales\SalesSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessWeighbridgeResultAction
{
    private const FINANCIAL_TOLERANCE = 1000;

    private function tolerance(): int
    {
        return (int) config('financial.weighbridge_tolerance', self::FINANCIAL_TOLERANCE);
    }

    public function __construct(
        protected WeighbridgeReconciler $reconciler,
        protected WeighbridgeVatRouter $vatRouter,
        protected SupplierDebtHandler $debtHandler,
        protected InvoiceGenerator $invoiceGenerator,
        protected InventoryService $inventoryService,
        protected WalletTransactionEngine $walletEngine,
        protected DebtEngine $debtEngine,
        protected SalesSettings $salesSettings,
    ) {}

    public function execute(Order $order, float $actualWeight): array
    {
        if ($this->isAlreadyProcessed($order)) {
            Log::channel('financial')->warning("ProcessWeighbridgeResultAction skipped: order {$order->id} already weighed.");

            return $this->buildAlreadyProcessedResponse($order);
        }

        if ($actualWeight <= 0) {
            throw new \InvalidArgumentException('وزن باسکول باید بزرگ‌تر از صفر باشد.');
        }

        return DB::transaction(function () use ($order, $actualWeight) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \RuntimeException(__('orders.internal_order_not_found'));
            }

            if ($this->isAlreadyProcessed($locked)) {
                Log::channel('financial')->warning("Race detected: order {$locked->id} already weighed.");

                return $this->buildAlreadyProcessedResponse($locked);
            }

            // گارد وضعیت: باسکول فقط برای سفارشِ پرداخت‌شده در پایپلاین آماده‌سازی معناست.
            // بدون این گارد، ثبت وزن روی سفارش PENDING/لغوشده، محاسبات را روی snapshot های
            // مالیِ ثبت‌نشده (POO/PTS صفر) اجرا می‌کرد و وضعیت را به SHIPPED می‌پراند.
            // ‏`AWAITING_WEIGHT_CORRECTION` عمداً اینجاست: سفارشی که در شاخه‌ی
            // فراتر-از-تلورانس متوقف شده باید پس از تأیید دو طرف بتواند تعدیل
            // را ادامه دهد. بدون این ورودی، تأیید هم راه به جایی نمی‌برد و
            // سفارش برای همیشه قفل می‌ماند. گاردِ واقعی پایین‌تر است:
            // بازگشت به اینجا فقط با `isOverweightApproved()` مجاز است.
            $weighableStatuses = [
                Order::STATUS_PROCESSING,
                Order::STATUS_SHIPPED,
                Order::STATUS_AWAITING_WEIGHT_CORRECTION,
            ];
            if (
                $locked->{Order::PAYMENT_STATUS} !== Order::PAYMENT_PAID
                || ! in_array($locked->{Order::STATUS}, $weighableStatuses, true)
            ) {
                throw new \RuntimeException(__('weighbridge.errors.not_weighable', [
                    'status' => $locked->{Order::STATUS},
                ]));
            }

            // larastan نتیجهٔ `lockForUpdate()->first()` را `Model` می‌بیند نه `Order`؛
            // گاردهای بالا این تنگ‌سازی را تضمین می‌کنند و بدون آن، فراخوانیِ
            // متدهایی که `Order` می‌خواهند یک ورودیِ تازه به baseline اضافه می‌کرد.
            /** @var Order $locked */
            $locked->load('items', 'eliteYAR.badge');

            Log::channel('financial')->info("شروع پردازش باسکول سفارش {$locked->id}.");

            // Physical stock adjusts by the REAL weight delta (not the billing delta).
            //
            // اختلاف بین **همهٔ** ردیف‌ها به‌نسبتِ وزنشان تقسیم می‌شود. پیش از این
            // کلِ اختلاف روی `items->first()` می‌نشست، پس در سفارشِ چندردیفی
            // موجودیِ ردیفِ اول بیش از حد و بقیه اصلاً اصلاح نمی‌شد.
            $weightDiff = $actualWeight - (float) $locked->weight_before_scale;
            $this->inventoryService->updateStockAfterWeighbridgeForOrder($locked, $weightDiff);

            $result = $this->reconciler->reconcile($locked, $actualWeight);

            // اضافه‌بارِ فراتر از تلورانس: **هیچ لِگ مالی‌ای اجرا نمی‌شود** و
            // سفارش تا تأیید دو طرف متوقف می‌ماند (بند ۸.۲ گام ۷الف).
            //
            // این شاخه پیش از هر نوشتنِ مالی می‌آید — همان قاعده‌ی «داده‌ی
            // نامعتبر باید متوقف کند، نه پول جابه‌جا کند» که گاردِ
            // `official_price` صفر و گاردِ چندتأمین‌کننده هم دارند.
            // ‏`isOverweightApproved()` گاردِ برگشت است: وقتی هر دو طرف پذیرفته‌اند
            // همین وزنِ فراتر-از-تلورانس دیگر باید تعدیل شود، نه دوباره متوقف.
            // بدون این استثنا، تأییدِ خریدار سفارش را به همین شاخه برمی‌گرداند
            // و حلقه‌ی بی‌پایان می‌ساخت.
            if ($result->overweightPercent > $this->maxOverweightPercent() && ! $locked->isOverweightApproved()) {
                $locked->forceFill([
                    Order::WEIGHT_AFTER_SCALE => $actualWeight,
                    Order::STATUS => Order::STATUS_AWAITING_WEIGHT_CORRECTION,
                ])->save();

                Log::channel('financial')->warning(
                    "Order {$locked->id}: overweight {$result->overweightPercent}% exceeds the tolerance; settlement halted."
                );

                // درخواستِ تأیید را همین‌جا باز می‌کنیم تا سفارش با مبلغ و مهلت
                // مشخص منتظر بماند؛ رزولوشن از `RequestOverweightApprovalAction`
                // می‌آید. تزریق تنبل است چون آن کلاس خودش همین اکشن را تزریق
                // می‌کند و سازنده‌ی مستقیم یک حلقه‌ی وابستگی می‌ساخت.
                app(\App\Actions\Order\RequestOverweightApprovalAction::class)
                    ->open($locked, $result->diffPOO);

                return [
                    'status' => 'awaiting_weight_correction',
                    'old_weight' => (string) $locked->weight_before_scale,
                    'new_weight' => (string) $actualWeight,
                    'financial_diff' => (string) $result->diffPOO,
                    'final_amount' => (string) $locked->final_amount,
                ];
            }

            $isDeficit = $result->diffPOO < -$this->tolerance();
            $isSurplus = $result->diffPOO > $this->tolerance();
            $status = 'equal';

            if ($isDeficit) {
                $status = $this->debtHandler->handle($locked, abs($result->diffPOO), abs($result->diffPTS));
            } elseif ($isSurplus) {
                $status = $this->chargeOverweight($locked, $result);
            }

            // Doc I (always) + LO/LS (only when deficit)
            $this->invoiceGenerator->generateWeighbridgeDocs($locked, $isDeficit);

            $eliteYARGross = $this->vatRouter->route($locked, $result);

            $locked->forceFill([
                Order::WEIGHT_AFTER_SCALE => $actualWeight,
                Order::WEIGHING_COMPLETED_AT => now(),
                // سفارشی که خریدارش از سقف اعتبار گذشته حمل نمی‌شود تا بپردازد
                // (بند ۸.۲ گام ۷ب) — لِگ‌های مالی ثبت شده‌اند، بار متوقف است.
                Order::STATUS => $status === 'surplus_pending_buyer'
                    ? Order::STATUS_AWAITING_WEIGHT_CORRECTION
                    : Order::STATUS_SHIPPED,
                Order::PAYMENT_OF_OWNER => $result->newPOO,
                Order::PAYMENT_TO_SUPPLIER => $result->newPTS,
                Order::PAYMENT_TO_ELITE_YAR => $eliteYARGross,
                Order::SHIPPING_COST => $result->newShippingCost,
                Order::COUPON_DISCOUNT_AMOUNT => $result->newCouponDiscount,
                Order::FINAL_AMOUNT => $result->newPOO,
                // درخواستِ اضافه‌بار با اجرای تعدیل بسته می‌شود. مهرهای تأیید
                // عمداً می‌مانند (رد پای تصمیم دو طرف)؛ فقط مبلغ و مهلت پاک
                // می‌شوند تا کرانِ انقضا سفارشِ تسویه‌شده را باطل نکند.
                Order::OVERWEIGHT_PENDING_AMOUNT => 0,
                Order::OVERWEIGHT_DEADLINE => null,
            ])->save();

            Log::channel('financial')->info("باسکول نهایی شد. وضعیت: {$status}");

            return [
                'status' => $status,
                'old_weight' => (string) $locked->weight_before_scale,
                'new_weight' => (string) $actualWeight,
                'financial_diff' => (string) abs($result->diffPOO),
                'final_amount' => (string) $result->newPOO,
            ];
        }, 3);
    }

    private function maxOverweightPercent(): float
    {
        return $this->salesSettings->maxOverweightPercent();
    }

    /**
     * اضافه‌بارِ درون تلورانس — بند ۸.۲ گام ۷ب و ۷ج.
     *
     * ترتیب لِگ‌ها همان قاعده‌ی همیشگی است: اول از خریدار بگیر، بعد به
     * تأمین‌کننده بده، تا کیف پول سازه هرگز منفی نشود.
     *
     * اگر خریدار موجودیِ کافی ندارد، سفارش لزوماً متوقف نمی‌شود: تا سقفِ
     * اعتبارش مابقی به بدهی می‌رود و بار حرکت می‌کند (بند ۳.۱۲). فراتر از سقف،
     * بدهی **باز هم ثبت می‌شود** ولی سفارش تا پرداخت `awaiting_weight_correction`
     * می‌ماند — سند صریحاً می‌گوید هر دو، نه یکی.
     */
    private function chargeOverweight(Order $order, WeighbridgeReconcileResult $result): string
    {
        $deltaPOO = $result->diffPOO;
        $deltaPTS = $result->diffPTS;
        $payerId = $order->payerUserId();
        $supplierId = (int) ($order->items->first()?->supplier_user_id ?? 0);

        $balance = (float) (Wallet::where(Wallet::USER_ID, $payerId)->lockForUpdate()->value(Wallet::BALANCE) ?? 0);
        $available = min($deltaPOO, $balance);
        $shortfall = round($deltaPOO - $available, 2);

        $withinCredit = $shortfall <= 0
            || $this->debtEngine->fitsWithinCreditLimit($payerId, $shortfall);

        if ($available > 0) {
            $this->walletEngine->debitBuyer(
                $order, $available, FinancialCodes::STATUS_AVAILABLE,
                "مابه‌التفاوت اضافه‌بار سفارش {$order->order_number}"
            );
        }

        if ($shortfall > 0) {
            $this->debtEngine->incur(
                $payerId, $shortfall, DebtEntry::TYPE_OVERWEIGHT, $order,
                "اضافه‌بار سفارش {$order->order_number}"
            );
        }

        // سازه کل مابه‌التفاوت را می‌گیرد (چه نقد چه اعتباری) و سهم
        // تأمین‌کننده را از همان می‌پردازد؛ لِگ ۴ پس از ۳ می‌آید.
        $this->walletEngine->creditSystem(
            $order, $deltaPOO, FinancialCodes::STATUS_AVAILABLE,
            "دریافت مابه‌التفاوت اضافه‌بار سفارش {$order->order_number}"
        );

        if ($deltaPTS > 0 && $supplierId > 0) {
            $this->walletEngine->debitSystem(
                $order, $deltaPTS, FinancialCodes::STATUS_AVAILABLE,
                "پرداخت سهم اضافه‌بار به تأمین‌کننده سفارش {$order->order_number}"
            );
            $this->walletEngine->creditSupplier(
                $order, $deltaPTS, FinancialCodes::STATUS_AVAILABLE, $supplierId,
                "سهم اضافه‌بار سفارش {$order->order_number}"
            );
        }

        // وضعیت را اینجا نمی‌نویسیم — `forceFill` بعدیِ orchestrator رویش
        // می‌نوشت. رشته‌ی برگشتی تعیین می‌کند سفارش `shipped` شود یا معلق بماند.
        return $withinCredit ? 'surplus' : 'surplus_pending_buyer';
    }

    private function isAlreadyProcessed(Order $order): bool
    {
        return $order->weighing_completed_at !== null;
    }

    private function buildAlreadyProcessedResponse(Order $order): array
    {
        return [
            'status' => 'already_processed',
            'old_weight' => (string) $order->weight_before_scale,
            'new_weight' => (string) $order->weight_after_scale,
            'financial_diff' => '0',
            'final_amount' => (string) $order->final_amount,
        ];
    }
}
