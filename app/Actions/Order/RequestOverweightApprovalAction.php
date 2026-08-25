<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Actions\Financial\ProcessWeighbridgeResultAction;
use App\Constants\FinancialCodes;
use App\Exceptions\BusinessException;
use App\Jobs\Notification\SendSmsJob;
use App\Models\DebtEntry;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Financial\DebtEngine;
use App\Services\Financial\WalletTransactionEngine;
use App\Services\Sales\SalesSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * جریان تأیید دومرحله‌ای اضافه‌بار — بند ۳.۷ و ۸.۲ گام ۷الف سند (§۶.۱).
 *
 * ⚠️ **این کلاس یک بن‌بست را می‌بندد.** پیش از آن، `ProcessWeighbridgeResultAction`
 * سفارشی را که اضافه‌بارش از `max_overweight_percent` می‌گذشت به
 * `awaiting_weight_correction` می‌برد و هیچ مسیری برای خروج نبود — نه اکشنی،
 * نه روتی، و باسکولِ دوباره هم به‌خاطر گاردِ `$weighableStatuses` رد می‌شد.
 *
 * ترتیب اجباری است و از سند می‌آید: **اول تأمین‌کننده، بعد خریدار**. دلیلش
 * همان انضباطی است که `ReturnDamageService` دارد — مبلغِ ادعایی باید پیش از
 * رسیدن به مشتری، توسط طرفِ ثبت‌کننده تأیید شده باشد.
 *
 * **در این شاخه هیچ لِگ مالی‌ای اجرا نشده.** به همین دلیل رد و انقضا چیزی
 * برای برگرداندن ندارند: فقط محموله باطل و سفارش به `processing` بازمی‌گردد
 * (بند ۳.۷: «هیچ تسویه‌ای برنمی‌گردد»).
 */
class RequestOverweightApprovalAction
{
    public function __construct(
        private readonly SalesSettings $salesSettings,
        private readonly ProcessWeighbridgeResultAction $weighbridge,
        private readonly DebtEngine $debtEngine,
        private readonly WalletTransactionEngine $walletEngine,
    ) {}

    /**
     * گشودن درخواست — از شاخه‌ی فراتر-از-تلورانسِ `ProcessWeighbridgeResultAction`.
     *
     * وضعیت و وزن را خودِ فراخوان می‌نویسد (داخل همان تراکنش قفل‌شده)؛ اینجا
     * فقط مبلغ، مهلت و اعلان اضافه می‌شود تا نوشتن روی ردیف یک‌جا بماند.
     */
    public function open(Order $order, float $deltaPOO): void
    {
        $order->forceFill([
            Order::OVERWEIGHT_PENDING_AMOUNT => $deltaPOO,
            Order::OVERWEIGHT_SUPPLIER_APPROVED_AT => null,
            Order::OVERWEIGHT_BUYER_APPROVED_AT => null,
            Order::OVERWEIGHT_DEADLINE => now()->addHours($this->salesSettings->overweightResponseHours()),
        ])->save();

        Log::channel('financial')->warning(
            "Order {$order->id}: overweight approval requested for {$deltaPOO} rials."
        );

        $this->notify($this->supplierIdOf($order), 'sms.overweight_supplier_request', $order);
    }

    /** مرحله‌ی یک — تأمین‌کننده افزایش وزن را تأیید می‌کند. */
    public function approveBySupplier(Order $order): Order
    {
        $this->assertOpen($order);

        if ($order->{Order::OVERWEIGHT_SUPPLIER_APPROVED_AT} !== null) {
            throw new BusinessException(__('orders.overweight_already_supplier_approved'), 422);
        }

        $order->forceFill([Order::OVERWEIGHT_SUPPLIER_APPROVED_AT => now()])->save();

        $this->notify($order->payerUserId(), 'sms.overweight_buyer_request', $order);

        return $order;
    }

    /**
     * مرحله‌ی دو — خریدار می‌پذیرد و تعدیل واقعاً اجرا می‌شود.
     *
     * تأییدِ خریدار پیش از تأییدِ تأمین‌کننده رد می‌شود: بدون آن، خریدار وزنی
     * را می‌پذیرفت که هنوز طرفِ ثبت‌کننده‌اش پایش نایستاده.
     */
    public function approveByBuyer(Order $order): array
    {
        $this->assertOpen($order);

        if ($order->{Order::OVERWEIGHT_SUPPLIER_APPROVED_AT} === null) {
            throw new BusinessException(__('orders.overweight_supplier_first'), 422);
        }

        $order->forceFill([Order::OVERWEIGHT_BUYER_APPROVED_AT => now()])->save();

        // وزنِ ثبت‌شده در همان اجرای متوقف‌شده روی سفارش نشسته است، پس تعدیل با
        // همان عدد ادامه می‌یابد و باسکول دوباره خوانده نمی‌شود.
        $weight = (float) $order->{Order::WEIGHT_AFTER_SCALE};

        if ($weight <= 0) {
            throw new BusinessException(__('orders.overweight_missing_weight'), 422);
        }

        return $this->weighbridge->execute($order->refresh(), $weight);
    }

    /**
     * رد توسط هر یک از دو طرف، یا انقضای مهلت (کران).
     *
     * محموله‌های ثبت‌شده باطل و سفارش به `processing` بازمی‌گردد. `weight_after_scale`
     * هم پاک می‌شود چون عددی که هیچ‌کس نپذیرفته نباید روی سفارش بماند و
     * گزارش‌های وزن را آلوده کند.
     */
    public function reject(Order $order, string $rejectedBy, ?string $reason = null): Order
    {
        $this->assertOpen($order);

        DB::transaction(function () use ($order, $rejectedBy, $reason) {
            /** @var Order|null $locked */
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked || ! $locked->hasOpenOverweightRequest()) {
                return;
            }

            Shipment::where(Shipment::ORDER_ID, $locked->getKey())
                ->whereNotNull(Shipment::REGISTERED_AT)
                ->update([
                    Shipment::WAYBILL_REF => null,
                    Shipment::REGISTERED_AT => null,
                    Shipment::ACTUAL_WEIGHT => null,
                    Shipment::IS_FINAL => false,
                    Shipment::STATUS => Shipment::STATUS_PENDING,
                ]);

            $locked->forceFill([
                Order::STATUS => Order::STATUS_PROCESSING,
                Order::WEIGHT_AFTER_SCALE => null,
                Order::WAYBILL_REF => null,
                Order::OVERWEIGHT_PENDING_AMOUNT => 0,
                Order::OVERWEIGHT_SUPPLIER_APPROVED_AT => null,
                Order::OVERWEIGHT_BUYER_APPROVED_AT => null,
                Order::OVERWEIGHT_DEADLINE => null,
            ])->save();

            Log::channel('financial')->warning(
                "Order {$locked->id}: overweight request rejected by {$rejectedBy}. ".($reason ?? '')
            );
        });

        $this->notify($this->supplierIdOf($order), 'sms.overweight_rejected', $order);
        $this->notify($order->payerUserId(), 'sms.overweight_rejected', $order);

        return $order->refresh();
    }

    /**
     * شاخه‌ی دوم بن‌بست — بند ۸.۲ گام ۷ب: خریدار مابه‌التفاوت را می‌پردازد.
     *
     * برخلاف شاخه‌ی فراتر-از-تلورانس، **اینجا لِگ‌های مالی قبلاً اجرا شده‌اند**:
     * سازه پول را گرفته، سهم تأمین‌کننده پرداخت شده، و کمبودِ کیف پول خریدار
     * به‌صورت بدهی ثبت شده. تنها چیزی که مانده حمل است که تا تسویه‌ی بدهی
     * متوقف می‌ماند. پس اینجا چیزی «تعدیل» نمی‌شود — فقط بدهی از کیف پول
     * بازپرداخت و بار آزاد می‌شود.
     */
    public function settleShortfall(Order $order): Order
    {
        // ⚠️ گارد عمداً وضعیت را **شرط لازم نمی‌داند**، فقط بدهیِ همین سفارش را.
        //
        // نسخه‌ی اول فقط `AWAITING_WEIGHT_CORRECTION` را می‌پذیرفت، ولی
        // `chargeOverweight` دو خروجی دارد: خریدارِ فراتر از سقف اعتبار به آن
        // وضعیت می‌رود، و خریدارِ **درونِ** سقف مستقیم `SHIPPED` می‌شود در حالی
        // که بدهی‌اش ثبت شده. یعنی همان کسی که اعتبار داشت هیچ راهی برای
        // تسویه نداشت: سفارش‌گذاری و برداشتش قفل می‌ماند و لِگِ نامتوازنِ کیف
        // پول (`creditSystem(deltaPOO)` در برابر `debitBuyer(available)`) برای
        // همیشه در گزارشِ `financial:doctor` می‌ماند.
        if ($order->{Order::WEIGHING_COMPLETED_AT} === null) {
            throw new BusinessException(__('orders.overweight_no_shortfall'), 422);
        }

        $payerId = $order->payerUserId();
        $outstanding = $this->outstandingForOrder($payerId, $order);

        if ($outstanding <= 0.0) {
            // بدهی از راه دیگری تسویه شده — اگر بار هنوز متوقف است آزادش کن.
            if ($order->{Order::STATUS} === Order::STATUS_AWAITING_WEIGHT_CORRECTION) {
                $order->forceFill([Order::STATUS => Order::STATUS_SHIPPED])->save();

                return $order;
            }

            throw new BusinessException(__('orders.overweight_no_shortfall'), 422);
        }

        DB::transaction(function () use ($order, $payerId, $outstanding) {
            // ترتیب اجباری: اول از کیف پول کم کن، بعد دفتر بدهی را ببند. عکسش
            // یعنی بدهی صفر شود و بعد کسرِ کیف پول با موجودیِ ناکافی throw کند —
            // آن‌وقت rollback هست، ولی تا آن لحظه سفارش «تسویه‌شده» دیده می‌شد.
            $this->walletEngine->debitBuyer(
                $order, $outstanding, FinancialCodes::STATUS_AVAILABLE,
                "تسویه‌ی بدهی اضافه‌بار سفارش {$order->order_number}"
            );

            $this->debtEngine->repay(
                $payerId, $outstanding, $order,
                "تسویه‌ی بدهی اضافه‌بار سفارش {$order->order_number}"
            );

            // فقط بارِ **متوقف** آزاد می‌شود. حالا که گارد وضعیت‌های دیگر را هم
            // می‌پذیرد، نوشتنِ بی‌قیدِ `SHIPPED` سفارشِ تحویل‌شده را به عقب
            // برمی‌گرداند — بدهی تسویه می‌شد و وضعیت خراب.
            if ($order->{Order::STATUS} === Order::STATUS_AWAITING_WEIGHT_CORRECTION) {
                $order->forceFill([Order::STATUS => Order::STATUS_SHIPPED])->save();
            }
        });

        Log::channel('financial')->info(
            "Order {$order->id}: overweight shortfall of {$outstanding} settled; load released."
        );

        return $order->refresh();
    }

    /**
     * بدهیِ باقی‌ماندهٔ **همین سفارش**، نه کل بدهی شخص.
     *
     * ‏`DebtEngine::balanceFor()` مانده‌ی شخصی را می‌دهد؛ استفاده از آن اینجا
     * یعنی خریداری که بابت سفارش دیگری هم بدهکار است، با پرداختِ اضافه‌بارِ این
     * سفارش **کلِ** بدهی‌اش از کیف پول کسر شود. دفتر بدهی `order_id` دارد و
     * ردیف بازپرداخت منفی نوشته می‌شود، پس جمعِ ردیف‌های این سفارش دقیقاً همان
     * مانده‌ی همین سفارش است.
     *
     * سقفِ مانده‌ی شخصی هم اعمال می‌شود چون `repay()` خودش clamp می‌کند و بدون
     * این سقف ممکن بود بیشتر از بدهیِ واقعی از کیف پول کم شود.
     */
    private function outstandingForOrder(int $payerId, Order $order): float
    {
        $orderDebt = (float) DebtEntry::query()
            ->where(DebtEntry::USER_ID, $payerId)
            ->where(DebtEntry::ORDER_ID, $order->getKey())
            ->sum(DebtEntry::AMOUNT);

        return max(0.0, min($orderDebt, $this->debtEngine->balanceFor($payerId)));
    }

    private function assertOpen(Order $order): void
    {
        if (! $order->hasOpenOverweightRequest()) {
            throw new BusinessException(__('orders.overweight_no_open_request'), 422);
        }

        if ($order->{Order::STATUS} !== Order::STATUS_AWAITING_WEIGHT_CORRECTION) {
            throw new BusinessException(__('orders.overweight_wrong_status'), 422);
        }
    }

    /**
     * تأمین‌کننده‌ی سفارش از روی ردیف‌ها.
     *
     * ‏`assertSingleSupplier` تضمین می‌کند سفارش بیش از یک تأمین‌کننده ندارد،
     * پس اولین ردیف کافی است.
     */
    private function supplierIdOf(Order $order): ?int
    {
        $id = $order->items()->value('supplier_user_id');

        return $id === null ? null : (int) $id;
    }

    private function notify(?int $userId, string $key, Order $order): void
    {
        if ($userId === null || $userId <= 0) {
            return;
        }

        /** @var User|null $user */
        $user = User::whereKey($userId)->first();

        if ($user === null || ! $user->mobile) {
            return;
        }

        SendSmsJob::dispatch($user->mobile, __($key, [
            'order' => $order->order_number,
            'amount' => number_format((float) $order->{Order::OVERWEIGHT_PENDING_AMOUNT}),
        ]));
    }
}
