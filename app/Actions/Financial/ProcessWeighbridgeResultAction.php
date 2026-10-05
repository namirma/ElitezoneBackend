<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\Actions\Order\RequestOverweightApprovalAction;
use App\Constants\FinancialCodes;
use App\DataTransferObjects\Order\WeighbridgeReconcileResult;
use App\Models\CouponFundingLeg;
use App\Models\DebtEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PriceSetting;
use App\Models\Wallet;
use App\Services\Financial\DebtEngine;
use App\Services\Financial\SettlementCalculatorService;
use App\Services\Financial\SupplierDebtHandler;
use App\Services\Financial\WalletTransactionEngine;
use App\Services\Financial\WeighbridgeReconciler;
use App\Services\Financial\WeighbridgeVatRouter;
use App\Services\InventoryService;
use App\Services\Invoice\InvoiceGenerator;
use App\Services\Marketing\CouponFundingService;
use App\Services\Sales\SalesSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessWeighbridgeResultAction
{
    private const FINANCIAL_TOLERANCE = 1000;

    /**
     * آیا اختلافِ وزن زیرِ آستانه‌ی بی‌تأیید است؟ دو شرط، هر دو لازم (پرسشِ ۹ مدیریت):
     *  ۱. |اختلاف| ÷ وزنِ سفارش ≤ آستانه‌ی درصدیِ پنل (`weight_tolerance_percent`)؛
     *  ۲. ارزشِ کالای معادلِ اختلاف (|Δوزن| × قیمتِ خریدار، بی‌مالیات) ≤ سهمِ شرکت از همین
     *     سفارش (خالص از مالیات): استخرِ POO − PTS، منهای سهمِ الیت‌یار اگر سفارش الیت‌یارِ
     *     کمیسیون‌گیر دارد — همان تقسیمِ `WeighbridgeVatRouter`.
     */
    private function withinAutoTolerance(Order $order, float $orderedWeight, float $actualWeight): bool
    {
        $thresholdPercent = PriceSetting::getValueAsFloat(PriceSetting::CODE_WEIGHT_TOLERANCE_PERCENT, 0.0);
        if ($thresholdPercent <= 0.0 || $orderedWeight <= 0.0) {
            return false;
        }

        $deltaWeight = abs($actualWeight - $orderedWeight);
        if (($deltaWeight / $orderedWeight) * 100 > $thresholdPercent) {
            return false;
        }

        $calculator = app(SettlementCalculatorService::class);
        $buyerOp = (float) ($order->{Order::OFFICIAL_PRICE} ?: ($order->items->first()?->unit_price ?? 0));
        $deltaGoodsValue = $deltaWeight * $buyerOp;

        $pool = max(0.0, (float) $order->{Order::PAYMENT_OF_OWNER} - (float) $order->{Order::PAYMENT_TO_SUPPLIER});
        $eliteYARId = $order->{Order::ELITE_YAR_USER_ID};
        $hasCommissionedEliteYAR = $eliteYARId !== null && (int) $eliteYARId !== $order->payerUserId();
        $companyGross = $pool;
        if ($hasCommissionedEliteYAR) {
            $rates = $calculator->getCommissionRatesForOrder($order, $order->eliteYAR?->badge?->code ?? 'pioneer');
            $coPlusSsg = $rates['co_final_rate'] + $rates['ssg_final_rate'];
            $companyGross = $coPlusSsg > 0 ? $pool * ($rates['ssg_final_rate'] / $coPlusSsg) : 0.0;
        }
        $companyNet = $calculator->extractVatInRials($companyGross, $calculator->resolveVatRate($order))['net'];

        return $deltaGoodsValue <= $companyNet;
    }

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
        protected CouponFundingService $couponFunding,
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

            // larastan نتیجه‌ی `lockForUpdate()->first()` را `Model` می‌بیند نه `Order`؛
            // گاردهای بالا این تنگ‌سازی را تضمین می‌کنند و بدون آن، فراخوانیِ
            // متدهایی که `Order` می‌خواهند یک ورودیِ تازه به baseline اضافه می‌کرد.
            /** @var Order $locked */
            $locked->load('items', 'eliteYAR.badge');

            Log::channel('financial')->info("شروع پردازش باسکول سفارش {$locked->id}.");

            // Physical stock adjusts by the REAL weight delta (not the billing delta).
            //
            // اختلاف بین **همه‌ی** ردیف‌ها به‌نسبتِ وزنشان تقسیم می‌شود. پیش از این
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
            // **تصمیم مدیریت ۲۰۲۶-۰۹-۰۹، بندِ ۲:** «درصد اضافه وزن مجاز یا کاهش
            // وزن مجاز باسکول نسبت به سفارش را حذف کنید» — به‌جایش **هر اختلافِ
            // وزن** باید هم خریدار و هم تأمین‌کننده تأییدش کنند.
            //
            // پس شرط دیگر آستانه‌ی درصدی نیست. دو تغییرِ معنایی این‌جا هست:
            //
            //   ۱. آستانه برداشته شد — هر اختلافی، هرقدر کوچک، رضایتِ دو طرف
            //      می‌خواهد.
            //   ۲. **کم‌تحویلی هم شامل شد.** `overweightPercent` علامت‌دار است
            //      (`(actual − wbs) / wbs`)، پس شرطِ قبلی (`> tolerance`) ذاتاً
            //      فقط اضافه‌بار را می‌گرفت و کم‌تحویلی همیشه بی‌رضایت تعدیل
            //      می‌شد. مدیریت هر دو جهت را خواسته.
            //
            // مقایسه روی خودِ وزن‌هاست نه درصدِ مشتق: «هر اختلافی» را تحت‌اللفظی
            // می‌کند و از بازگرداندنِ یک آستانه‌ی پنهان (اپسیلون) جلوگیری می‌کند.
            $orderedWeight = (float) $locked->{Order::WEIGHT_BEFORE_SCALE};
            $weightDiffers = $orderedWeight > 0.0 && $actualWeight !== $orderedWeight;

            // **پاسخِ مدیریت به پرسشِ ۹ (۱۴۰۵/۰۷/۱۳، گزینه‌ی ج):** اختلاف‌های خیلی کوچک بدونِ
            // تأییدِ دوطرفه عبور می‌کنند؛ آستانه (درصد) در «پارامترهای مالی» است و سقفِ
            // خودکارش «کالای معادلِ سهمِ شرکت از همین سفارش». پیش‌فرضِ صفر = رفتارِ ۰۹-۰۹.
            if ($weightDiffers && ! $locked->isOverweightApproved() && $this->withinAutoTolerance($locked, $orderedWeight, $actualWeight)) {
                Log::channel('financial')->info(sprintf(
                    'Order %d: weighed %s vs ordered %s (%+.2f%%) is within the auto tolerance; settling without two-party consent.',
                    $locked->id, $actualWeight, $orderedWeight, $result->overweightPercent,
                ));
                $weightDiffers = false;
            }

            if ($weightDiffers && ! $locked->isOverweightApproved()) {
                $locked->forceFill([
                    Order::WEIGHT_AFTER_SCALE => $actualWeight,
                    Order::STATUS => Order::STATUS_AWAITING_WEIGHT_CORRECTION,
                ])->save();

                // پیامِ قبلی «exceeds the tolerance» می‌گفت، در حالی که آستانه‌ای
                // دیگر وجود ندارد — و چون `overweightPercent` علامت‌دار است، برای
                // کم‌تحویلی می‌شد «overweight -3% exceeds the tolerance». هر دو
                // نیمه‌اش برای کسی که لاگِ مالی را می‌خواند گمراه‌کننده بود.
                Log::channel('financial')->warning(sprintf(
                    'Order %d: weighed %s vs ordered %s (%+.2f%%); awaiting two-party consent, settlement halted.',
                    $locked->id,
                    $actualWeight,
                    $orderedWeight,
                    $result->overweightPercent,
                ));

                // درخواستِ تأیید را همین‌جا باز می‌کنیم تا سفارش با مبلغ و مهلت
                // مشخص منتظر بماند؛ رزولوشن از `RequestOverweightApprovalAction`
                // می‌آید. تزریق تنبل است چون آن کلاس خودش همین اکشن را تزریق
                // می‌کند و سازنده‌ی مستقیم یک حلقه‌ی وابستگی می‌ساخت.
                app(RequestOverweightApprovalAction::class)
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

            // تخفیفِ کوپنِ درصدی با POOM عوض می‌شود، پس پولش هم باید جابه‌جا شود —
            // وگرنه در کم‌تحویلی خریدار کلِ تخفیفِ اولیه را نگه می‌داشت و سفارش عددِ
            // کوچک‌تری ثبت می‌کرد (WSSG ضرر)، و در اضافه‌بار برعکس. اختلاف فقط وقتی
            // اعمال می‌شود که لِگ‌های همان جهت اجرا شوند؛ درونِ تلورانس هیچ لِگی نیست
            // و تخفیفِ ثبت‌شده هم دست نمی‌خورد.
            //
            // از ۲۰۲۶-۰۹-۱۵ تخفیف را سه منبع تأمین می‌کنند (`coupon_funding_legs`)، پس
            // اختلاف هم به **همان تأمین‌کنندگان و به همان نسبتِ لِگ‌های پرداخت** برمی‌گردد
            // یا از آن‌ها برداشته می‌شود، نه فقط WSSG↔WO.
            $couponShares = [];
            if (($isDeficit && $result->couponDiscountDelta < 0) || ($isSurplus && $result->couponDiscountDelta > 0)) {
                $couponShares = $this->couponFunding->weighbridgeShares(
                    $locked,
                    $result->newCouponDiscount - $result->couponDiscountDelta,
                    $result->newCouponDiscount,
                );
            }
            $couponApplied = false;

            if ($isDeficit) {
                $status = $this->debtHandler->handle($locked, abs($result->diffPOO), abs($result->diffPTS), $couponShares);
                // پارک (`deficit_pending_supplier`) پول جابه‌جا نمی‌کند و سهم‌ها را اعمال نمی‌کند.
                $couponApplied = $couponShares !== [] && $status === 'deficit';
            } elseif ($isSurplus) {
                $firstItem = $locked->items->first();
                /** @var OrderItem|null $firstItem */
                $incoming = $firstItem ? [(int) $firstItem->supplier_user_id => max(0.0, $result->diffPTS)] : [];

                // تأمین‌کننده‌ای که پولِ سهمِ اضافه را ندارد نباید کلِ باسکول را rollback کند؛
                // در آن حالت تخفیفِ اضافه داده نمی‌شود و تخفیفِ ثبت‌شده همان می‌ماند.
                if ($couponShares !== [] && ! $this->couponFunding->canFundIncrease($couponShares, $incoming)) {
                    $couponShares = [];
                }

                $status = $this->chargeOverweight($locked, $result, $couponShares);
                $couponApplied = $couponShares !== [];
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
                // عددِ ثبت‌شده باید همان باشد که پولش جابه‌جا شد — `RefundOrderToWalletAction`
                // همین ستون را برعکس می‌کند.
                Order::COUPON_DISCOUNT_AMOUNT => $couponApplied
                    ? $result->newCouponDiscount
                    : $locked->{Order::COUPON_DISCOUNT_AMOUNT},
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

    // ‏`maxOverweightPercent()` این‌جا حذف شد: تنها مصرف‌کننده‌اش گاردِ تلورانس
    // بود که در تصمیم مدیریت ۲۰۲۶-۰۹-۰۹ (بندِ ۲) برداشته شد، و یک متدِ خصوصیِ
    // بی‌صدازننده هم کدِ مرده است هم PHPStan را قرمز می‌کند.
    //
    // ⚠️ این پاراگراف قبلاً می‌گفت خودِ تنظیم «عمداً دست‌نخورده ماند». دیگر
    // درست نیست: کامیتِ بعدیِ همان نشست (`479fdb2`) آن را از
    // `SalesSettings`، `config/sales.php` و `config/settings.php` هم برداشت.
    // هیچ `maxOverweightPercent()`ای برای صدازدن نمانده.
    //
    // ولی نیمه‌ی بک‌آفیسِ ماجرا هنوز باز است: اگر فیلدش در صفحه‌ی تنظیمات مانده
    // باشد، ادمین عددی را تنظیم می‌کند که هیچ اثری ندارد — و بدتر، چون کلید از
    // `allowed_keys` هم رفته، ذخیره‌اش رد می‌شود. ردیفِ `W1` در
    // `docs/session/CLEANUP_CANDIDATES.md` همان است.

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
    /**
     * @param  list<array{leg: CouponFundingLeg|null, type: string, user_id: int|null, delta: float}>  $couponShares
     */
    private function chargeOverweight(Order $order, WeighbridgeReconcileResult $result, array $couponShares = []): string
    {
        $deltaPOO = $result->diffPOO;
        $deltaPTS = $result->diffPTS;
        $payerId = $order->payerUserId();
        // متغیرِ تایپ‌دار — larastan روی این شاخه آیتم‌های رابطه را `Model` می‌بیند.
        $firstItem = $order->items->first();
        /** @var OrderItem|null $firstItem */
        $supplierId = $firstItem ? (int) $firstItem->supplier_user_id : 0;

        // تخفیفِ تکمیلیِ کوپن — همان ترتیبِ پرداخت: اعتبار به خریدار **پیش از** خواندنِ
        // موجودی و برداشت، تا فقط «مابه‌التفاوت − تخفیف» لازم باشد (و کمتر به بدهی برود)؛
        // برداشت از تأمین‌کنندگان پایین‌تر و پس از لِگِ PTS، تا WSSG و WS منفی نشوند.
        $couponExtra = (float) array_sum(array_column($couponShares, 'delta'));
        $couponDesc = '';
        if ($couponExtra > 0) {
            $couponDesc = $this->couponFunding->weighbridgeDescription($order, 'coupons.weighbridge_extra_description');
            $this->walletEngine->creditBuyer($order, $couponExtra, FinancialCodes::STATUS_AVAILABLE, $couponDesc);
        }

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

        if ($couponExtra > 0) {
            $this->couponFunding->applyWeighbridgeShares($order, $couponShares, $couponDesc);
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
