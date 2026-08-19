<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Constants\FinancialCodes;
use App\Exceptions\BusinessException;
use App\Jobs\Notification\SendSmsJob;
use App\Models\DebtEntry;
use App\Models\Order;
use App\Models\ReturnDamage;
use App\Models\Shipment;
use App\Services\Financial\DebtEngine;
use App\Services\Financial\WalletTransactionEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * چرخه‌ی خسارتِ عودت به مبدأ — فاز ۱۱، بند ۳.۱۱ و ۸.۳ سند.
 *
 * ⚠️ **اصل مالکیت، که هر تصمیمِ این فایل از آن می‌آید:** کالا از آنِ خریدار و
 * پول از آنِ تأمین‌کننده. با عودت، تأمین‌کننده **بدهکار نمی‌شود** — کالا را
 * نگه می‌دارد و خریدار بابت هزینه‌ها به او بدهکار می‌شود. به همین دلیل خودِ
 * عودت (در `ExpireShipmentDeliveries`) هیچ لِگ مالی ندارد.
 *
 * جریان سه‌مرحله‌ای است و ترتیبش اجباری: تأمین‌کننده ثبت می‌کند → بک‌آفیس
 * تأیید می‌کند → خریدار تأیید می‌کند. تنها در مرحله‌ی سوم بدهی ثبت می‌شود.
 * تأییدِ بک‌آفیس عمداً پیش از خریدار است تا مبلغِ ادعایی پیش از رسیدن به
 * مشتری بررسی شده باشد.
 */
class ReturnDamageService
{
    public function __construct(
        private readonly DebtEngine $debtEngine,
        private readonly WalletTransactionEngine $walletEngine,
    ) {}

    /**
     * ثبت هزینه توسط تأمین‌کننده — بند ۷.۳.
     *
     * فقط روی محموله‌ی **عودت‌شده**: هزینه‌ی عودت پیش از عودت وجود ندارد، و
     * بدون این گارد تأمین‌کننده می‌توانست روی باری که هنوز در راه است هزینه
     * ثبت کند.
     */
    public function record(Shipment $shipment, float $amount, string $description): ReturnDamage
    {
        if ($shipment->{Shipment::RETURNED_AT} === null) {
            throw new BusinessException(__('shipments.damage_only_on_returned'), 422);
        }

        return ReturnDamage::create([
            ReturnDamage::SHIPMENT_ID => $shipment->id,
            ReturnDamage::ORDER_ID => $shipment->{Shipment::ORDER_ID},
            ReturnDamage::AMOUNT => $amount,
            ReturnDamage::DESCRIPTION => $description,
        ]);
    }

    /** مرحله‌ی دوم — بند ۳.۱۱: «تأیید بک‌آفیس الزامی است، سپس تأیید خریدار». */
    public function approveByBackoffice(ReturnDamage $damage, int $adminId): ReturnDamage
    {
        $this->assertNotRejected($damage);

        if ($damage->{ReturnDamage::BACKOFFICE_APPROVED_AT} !== null) {
            throw new BusinessException(__('shipments.damage_already_backoffice_approved'), 422);
        }

        $damage->forceFill([
            ReturnDamage::BACKOFFICE_APPROVED_AT => now(),
            ReturnDamage::BACKOFFICE_APPROVED_BY => $adminId,
        ])->save();

        $this->notifyBuyer($damage);

        return $damage;
    }

    /**
     * مرحله‌ی سوم — تأیید خریدار. **تنها جایی که بدهی ثبت می‌شود.**
     *
     * بند ۳.۱۱: «عدم تأیید خریدار ⇒ تیکت پشتیبانی» — یعنی رد کردن یک مسیر
     * معتبر است و نباید چیزی را مالی کند؛ `reject()` همان را انجام می‌دهد.
     */
    public function approveByBuyer(ReturnDamage $damage): ReturnDamage
    {
        $this->assertNotRejected($damage);

        if ($damage->{ReturnDamage::BACKOFFICE_APPROVED_AT} === null) {
            throw new BusinessException(__('shipments.damage_needs_backoffice_first'), 422);
        }

        if ($damage->{ReturnDamage::BUYER_APPROVED_AT} !== null) {
            throw new BusinessException(__('shipments.damage_already_buyer_approved'), 422);
        }

        return DB::transaction(function () use ($damage) {
            /** @var Order $order */
            $order = Order::where(Order::ID, $damage->{ReturnDamage::ORDER_ID})->lockForUpdate()->first();

            $damage->forceFill([ReturnDamage::BUYER_APPROVED_AT => now()])->save();

            $this->debtEngine->incur(
                $order->payerUserId(),
                (float) $damage->{ReturnDamage::AMOUNT},
                DebtEntry::TYPE_RETURN_DAMAGE,
                $order,
                $damage->{ReturnDamage::DESCRIPTION},
                (int) $damage->{ReturnDamage::SHIPMENT_ID},
            );

            $this->applyCapIfReached($order);

            return $damage->fresh();
        });
    }

    public function reject(ReturnDamage $damage, string $reason): ReturnDamage
    {
        $this->assertNotRejected($damage);

        if ($damage->{ReturnDamage::BUYER_APPROVED_AT} !== null) {
            throw new BusinessException(__('shipments.damage_already_buyer_approved'), 422);
        }

        $damage->forceFill([
            ReturnDamage::REJECTED_AT => now(),
            ReturnDamage::REJECTION_REASON => $reason,
        ])->save();

        return $damage;
    }

    /**
     * پرداخت خسارت — بند ۸.۳، به همین ترتیب:
     *   `debitBuyer` → `creditSupplier` → `DebtEngine::repay` → محموله `pending`.
     *
     * بازگرداندنِ محموله به `pending` همان چیزی است که «چرخه تکرارپذیر است» را
     * ممکن می‌کند: تأمین‌کننده می‌تواند دوباره بارنامه بزند و بار را بفرستد.
     * مهرهای تحویلِ قبلی پاک می‌شوند وگرنه مهلتِ سوخته‌ی دور قبل بلافاصله
     * دوباره عودت را راه می‌انداخت.
     */
    public function settle(ReturnDamage $damage): ReturnDamage
    {
        if ($damage->{ReturnDamage::BUYER_APPROVED_AT} === null) {
            throw new BusinessException(__('shipments.damage_needs_backoffice_first'), 422);
        }

        if ($damage->{ReturnDamage::SETTLED_AT} !== null) {
            return $damage;
        }

        return DB::transaction(function () use ($damage) {
            /** @var Order $order */
            $order = Order::where(Order::ID, $damage->{ReturnDamage::ORDER_ID})
                ->lockForUpdate()
                ->first();

            $order->load('items');
            $amount = (float) $damage->{ReturnDamage::AMOUNT};

            $firstItem = $order->items->first();
            $supplierId = $firstItem ? (int) $firstItem->supplier_user_id : 0;

            if ($supplierId <= 0) {
                throw new \RuntimeException(
                    "ReturnDamageService: order {$order->id} has no supplier — the damage cannot be paid out."
                );
            }

            $description = __('shipments.damage_settled');

            $this->walletEngine->debitBuyer($order, $amount, FinancialCodes::STATUS_AVAILABLE, $description);
            $this->walletEngine->creditSupplier($order, $amount, FinancialCodes::STATUS_AVAILABLE, $supplierId, $description);
            $this->debtEngine->repay($order->payerUserId(), $amount, $order, $description);

            $damage->forceFill([ReturnDamage::SETTLED_AT => now()])->save();

            /** @var Shipment $shipment */
            $shipment = Shipment::where(Shipment::ID, $damage->{ReturnDamage::SHIPMENT_ID})
                ->lockForUpdate()
                ->first();

            $shipment->forceFill([
                Shipment::STATUS => Shipment::STATUS_PENDING,
                Shipment::RETURNED_AT => null,
                Shipment::ARRIVED_AT => null,
                Shipment::ARRIVAL_SOURCE => null,
                Shipment::DELIVERY_DEADLINE => null,
                // بارنامه هم پاک می‌شود تا حملِ مجدد بتواند شماره‌ی تازه بگیرد؛
                // گاردِ idempotency در `RegisterShipmentAction` روی همین ستون است.
                Shipment::REGISTERED_AT => null,
                Shipment::WAYBILL_REF => null,
            ])->save();

            $this->notifySettled($order);

            return $damage->fresh();
        });
    }

    /**
     * سقف — بند ۳.۱۱ و ۸.۳.
     *
     * «تا وقتی `Σ damages < مبلغ کل کالا` کالا از آنِ خریدار است. با برابرشدن:
     * بدهی خریدار با مبلغ کالا تسویه، کالا از آنِ تأمین‌کننده، سفارش منقضی،
     * **هیچ مبلغی به خریدار بازنمی‌گردد**.»
     *
     * «مبلغ کل کالا» طبق تعریفِ صریحِ سند `orders.subtotal` است — بهای کالا
     * پیش از کرایه/کمیسیون/مالیات، نه `final_amount`.
     *
     * ⚠️ **موجودی آزاد نمی‌شود.** لغوِ معمولی سفارش موجودی را برمی‌گرداند، ولی
     * اینجا کالا واقعاً تحویل تأمین‌کننده شده و به انبار برنگشته؛ آزادکردنش
     * یعنی همان تناژ دو بار فروخته شود. به همین دلیل `CancelOrderAction` صدا
     * زده **نمی‌شود** و وضعیت مستقیم نوشته می‌گردد.
     */
    private function applyCapIfReached(Order $order): void
    {
        $subtotal = (float) $order->{Order::SUBTOTAL};

        if ($subtotal <= 0) {
            return;
        }

        $approved = (float) ReturnDamage::query()
            ->where(ReturnDamage::ORDER_ID, $order->id)
            ->whereNotNull(ReturnDamage::BUYER_APPROVED_AT)
            ->whereNull(ReturnDamage::REJECTED_AT)
            ->sum(ReturnDamage::AMOUNT);

        if ($approved < $subtotal) {
            return;
        }

        $this->debtEngine->settleAgainstGoods($order->payerUserId(), $order);

        $order->forceFill([
            Order::STATUS => Order::STATUS_CANCELLED,
            Order::CANCEL_REASON => __('shipments.damage_cap_reached'),
        ])->save();

        Log::channel('financial')->warning(
            "Order {$order->id}: return damages ({$approved}) reached the goods value ({$subtotal}); goods transferred to the supplier."
        );

        $buyer = $order->payer ?? $order->placer;

        if ($buyer?->mobile) {
            SendSmsJob::dispatch(
                $buyer->mobile,
                __('sms.return_damage_cap_reached', ['order_number' => $order->order_number])
            )->afterCommit();
        }
    }

    private function assertNotRejected(ReturnDamage $damage): void
    {
        if ($damage->{ReturnDamage::REJECTED_AT} !== null) {
            throw new BusinessException(__('shipments.damage_rejected_already'), 422);
        }
    }

    private function notifyBuyer(ReturnDamage $damage): void
    {
        $order = $damage->order;
        $buyer = $order?->payer ?? $order?->placer;

        if (! $buyer?->mobile) {
            return;
        }

        SendSmsJob::dispatch(
            $buyer->mobile,
            __('sms.return_damage_awaiting_buyer', [
                'order_number' => $order->order_number,
                'amount' => number_format((int) $damage->{ReturnDamage::AMOUNT}),
            ])
        )->afterCommit();
    }

    private function notifySettled(Order $order): void
    {
        $supplier = $order->items->first()?->supplier;

        if (! $supplier?->mobile) {
            return;
        }

        SendSmsJob::dispatch(
            $supplier->mobile,
            __('sms.return_damage_settled', ['order_number' => $order->order_number])
        )->afterCommit();
    }
}
