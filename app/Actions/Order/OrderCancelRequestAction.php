<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\OrderCancelRequest;
use App\Models\User;
use App\Services\Notifier;
use Hekmatinasser\Verta\Verta;
use Illuminate\Support\Facades\DB;

/**
 * لغوِ دوطرفه‌ی سفارش با ارجاع به بک‌آفیس — تصمیم مدیریت ۲۰۲۶-۰۹-۰۹، پرسش ۴.
 *
 * «خریدار یا سوپلایر شروع می‌کند، ولی توافق هر دو لازم است. اگر یکی مخالف بود →
 * ارجاع به بک‌آفیس و تصمیم نهایی آن‌جا.» مسیرِ مالی (پاسخ ۷ مدیریت): «مهلت پاسخ
 * ۴۸ ساعت است. اگر رد شد دلیلش گفته می‌شود. اگر … توسط بک‌آفیس پذیرفته شود، پول
 * از کیف پول تأمین‌کننده کسر و به کیف پول خریدار اضافه می‌شود و سفارش لغو می‌گردد.»
 *
 * ## چه سفارشی
 *
 * فقط `processing` و پیش از باسکول. رزرو مسیرِ خودش را دارد (پرسش ۳: «رزروِ اول
 * را هیچ‌کس نمی‌تواند کنسل کند» — ردِ تمدید همان لغو است)، و پس از باسکول
 * پول تقسیم و هولد شده و `RefundOrderToWalletAction` عمداً ردش می‌کند؛ آن‌جا
 * مسیرِ مرجوعی/داوری است، نه لغو.
 *
 * ## پول
 *
 * هیچ لِگی این‌جا نوشته نشده. هر لغو — چه با توافق، چه با تصمیمِ بک‌آفیس — از
 * `CancelOrderAction` می‌گذرد که برای سفارشِ پرداخت‌شده `RefundOrderToWalletAction`
 * را می‌زند: WS(−PTS) → WSSG(+PTS)، سپس WSSG(−POO) → WO(+POO). یعنی دقیقاً «از
 * کیف پول تأمین‌کننده کسر، به کیف پول خریدار اضافه» — از راهِ حسابداری‌شده‌ی
 * همیشگی، نه یک انتقالِ مستقیمِ تازه.
 *
 * ## دو پیش‌فرضِ خودمان (مدیریت پاسخ نداده بود)
 *
 *   • مهلتِ ۴۸ ساعت از **ثبتِ درخواست** شمرده می‌شود.
 *   • پایانِ مهلتِ بی‌پاسخ = **ارجاع**، نه پذیرش/ردِ خودکار. پول هرگز بدونِ تصمیمِ
 *     یک آدم جابه‌جا نمی‌شود (`orders:escalate-cancel-requests`).
 *
 * پوششِ تست: tests/Feature/Order/OrderCancelRequestTest.php
 */
final class OrderCancelRequestAction
{
    public const RESPONSE_HOURS = 48;

    public function __construct(
        private readonly CancelOrderAction $cancel,
    ) {}

    /** ثبتِ درخواست از یکی از دو طرف. */
    public function request(Order $order, string $side, int $requesterUserId, string $reason): OrderCancelRequest
    {
        $request = DB::transaction(function () use ($order, $side, $requesterUserId, $reason) {
            /** @var Order $locked */
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            $this->assertCancellable($locked);

            if ($locked->cancelRequests()->whereIn(OrderCancelRequest::STATUS, OrderCancelRequest::OPEN_STATUSES)->exists()) {
                throw new BusinessException(__('orders.cancel_request.already_open'), 409);
            }

            $request = new OrderCancelRequest([
                OrderCancelRequest::ORDER_ID => $locked->getKey(),
                OrderCancelRequest::REQUESTED_BY => $side,
                OrderCancelRequest::REQUESTER_USER_ID => $requesterUserId,
                OrderCancelRequest::REASON => $reason,
            ]);

            // مهلت را سامانه می‌نویسد، نه بدنه‌ی درخواست — همان قاعده‌ی
            // `SupplierRequest::REVIEW_DEADLINE`.
            $request->forceFill([
                OrderCancelRequest::RESPOND_UNTIL => now()->addHours(self::RESPONSE_HOURS),
            ])->save();

            return $request;
        });

        $this->notify($this->counterpartyUserId($order, $request), 'order_cancel_requested', $order, [
            'until' => Verta::instance($request->{OrderCancelRequest::RESPOND_UNTIL})->format('Y/m/d H:i'),
        ]);

        return $request;
    }

    /** طرفِ مقابل می‌پذیرد → توافقِ دوطرفه → لغو همین حالا. */
    public function accept(OrderCancelRequest $request): Order
    {
        $order = DB::transaction(function () use ($request) {
            $locked = $this->lockOpen($request, OrderCancelRequest::STATUS_PENDING);

            $locked->forceFill([
                OrderCancelRequest::COUNTERPARTY_DECISION => OrderCancelRequest::DECISION_ACCEPTED,
                OrderCancelRequest::RESPONDED_AT => now(),
                OrderCancelRequest::STATUS => OrderCancelRequest::STATUS_APPROVED,
                OrderCancelRequest::DECIDED_AT => now(),
            ])->save();

            return $this->cancelOrder($locked, __('orders.cancel_request.mutual_cancel_reason'));
        });

        $this->notify((int) $request->{OrderCancelRequest::REQUESTER_USER_ID}, 'order_cancel_accepted', $order);

        return $order;
    }

    /** طرفِ مقابل رد می‌کند (با دلیل) → ارجاع به بک‌آفیس. */
    public function reject(OrderCancelRequest $request, string $reason): OrderCancelRequest
    {
        $locked = DB::transaction(function () use ($request, $reason) {
            $locked = $this->lockOpen($request, OrderCancelRequest::STATUS_PENDING);

            $locked->forceFill([
                OrderCancelRequest::COUNTERPARTY_DECISION => OrderCancelRequest::DECISION_REJECTED,
                OrderCancelRequest::COUNTERPARTY_REASON => $reason,
                OrderCancelRequest::RESPONDED_AT => now(),
                OrderCancelRequest::ESCALATED_AT => now(),
                OrderCancelRequest::STATUS => OrderCancelRequest::STATUS_ESCALATED,
            ])->save();

            return $locked;
        });

        $this->notify((int) $locked->{OrderCancelRequest::REQUESTER_USER_ID}, 'order_cancel_rejected', $locked->order, [
            'reason' => $reason,
        ]);

        return $locked;
    }

    /**
     * مهلتِ پاسخ گذشت و کسی جواب نداد → ارجاع (کرونِ ساعتی).
     *
     * زیرِ قفل دوباره سنجیده می‌شود: بینِ SELECT کرون و این‌جا، طرفِ مقابل
     * می‌تواند پاسخ داده باشد — همان انضباطِ `ExpirePriceChangeRequests`.
     */
    public function escalateTimedOut(OrderCancelRequest $request): bool
    {
        $escalated = DB::transaction(function () use ($request) {
            /** @var OrderCancelRequest $locked */
            $locked = OrderCancelRequest::whereKey($request->getKey())->lockForUpdate()->first();

            if (! $locked->isPending() || $locked->{OrderCancelRequest::RESPOND_UNTIL}->isFuture()) {
                return null;
            }

            $locked->forceFill([
                OrderCancelRequest::ESCALATED_AT => now(),
                OrderCancelRequest::STATUS => OrderCancelRequest::STATUS_ESCALATED,
            ])->save();

            return $locked;
        });

        if ($escalated === null) {
            return false;
        }

        $order = $escalated->order;
        $this->notify((int) $escalated->{OrderCancelRequest::REQUESTER_USER_ID}, 'order_cancel_escalated', $order);
        $this->notify($this->counterpartyUserId($order, $escalated), 'order_cancel_escalated', $order);

        return true;
    }

    /** بک‌آفیس می‌پذیرد → لغو با همان مسیرِ استرداد. تصمیم نهایی است. */
    public function adminApprove(OrderCancelRequest $request, int $adminId, string $reason): Order
    {
        $order = DB::transaction(function () use ($request, $adminId, $reason) {
            $locked = $this->lockOpen($request, OrderCancelRequest::STATUS_ESCALATED);

            $locked->forceFill([
                OrderCancelRequest::ADMIN_DECISION => OrderCancelRequest::DECISION_APPROVED,
                OrderCancelRequest::ADMIN_ID => $adminId,
                OrderCancelRequest::ADMIN_REASON => $reason,
                OrderCancelRequest::DECIDED_AT => now(),
                OrderCancelRequest::STATUS => OrderCancelRequest::STATUS_APPROVED,
            ])->save();

            return $this->cancelOrder($locked, $reason);
        });

        $this->notifyBothSides($request, $order, 'order_cancel_admin_approved', ['reason' => $reason]);

        return $order;
    }

    /** بک‌آفیس رد می‌کند → سفارش ادامه می‌یابد؛ دلیل به هر دو طرف. */
    public function adminReject(OrderCancelRequest $request, int $adminId, string $reason): OrderCancelRequest
    {
        $locked = DB::transaction(function () use ($request, $adminId, $reason) {
            $locked = $this->lockOpen($request, OrderCancelRequest::STATUS_ESCALATED);

            $locked->forceFill([
                OrderCancelRequest::ADMIN_DECISION => OrderCancelRequest::DECISION_REJECTED,
                OrderCancelRequest::ADMIN_ID => $adminId,
                OrderCancelRequest::ADMIN_REASON => $reason,
                OrderCancelRequest::DECIDED_AT => now(),
                OrderCancelRequest::STATUS => OrderCancelRequest::STATUS_REJECTED,
            ])->save();

            return $locked;
        });

        $this->notifyBothSides($locked, $locked->order, 'order_cancel_admin_rejected', ['reason' => $reason]);

        return $locked;
    }

    /**
     * فقط `processing` و پیش از باسکول — دلیلش در سرِ کلاس.
     */
    private function assertCancellable(Order $order): void
    {
        if ($order->{Order::STATUS} !== Order::STATUS_PROCESSING
            || $order->{Order::WEIGHING_COMPLETED_AT} !== null) {
            throw new BusinessException(__('orders.cancel_request.not_cancellable'), 422);
        }
    }

    private function lockOpen(OrderCancelRequest $request, string $expectedStatus): OrderCancelRequest
    {
        /** @var OrderCancelRequest $locked */
        $locked = OrderCancelRequest::whereKey($request->getKey())->lockForUpdate()->first();

        if ($locked->{OrderCancelRequest::STATUS} !== $expectedStatus) {
            throw new BusinessException(__('orders.cancel_request.already_answered'), 409);
        }

        return $locked;
    }

    /**
     * `isSystemCron: true` = «مشمولِ پنجره‌ی لغوِ خریدار نیست» (نامِ تاریخیِ پرچم —
     * `CancelOrderAction` را ببینید). سفارشِ `processing` اصلاً به آن گارد نمی‌رسد،
     * ولی پرچم صریح می‌ماند تا معنای این لغو (تصمیمِ دوطرفه/بک‌آفیس) گم نشود.
     */
    private function cancelOrder(OrderCancelRequest $request, string $reason): Order
    {
        return $this->cancel->execute($request->order, $reason, refundWallet: true, isSystemCron: true);
    }

    private function counterpartyUserId(Order $order, OrderCancelRequest $request): ?int
    {
        return $request->{OrderCancelRequest::REQUESTED_BY} === OrderCancelRequest::SIDE_BUYER
            ? $order->supplierUserId()
            : $order->payerUserId();
    }

    /** @param  array<string, string>  $extra */
    private function notifyBothSides(OrderCancelRequest $request, Order $order, string $key, array $extra): void
    {
        $this->notify((int) $request->{OrderCancelRequest::REQUESTER_USER_ID}, $key, $order, $extra);
        $this->notify($this->counterpartyUserId($order, $request), $key, $order, $extra);
    }

    /** @param  array<string, string>  $extra */
    private function notify(?int $userId, string $key, Order $order, array $extra = []): void
    {
        $user = $userId ? User::find($userId) : null;

        if (! $user instanceof User) {
            return;
        }

        Notifier::toUser(
            $user,
            __('notifications.'.$key.'_subject'),
            __('sms.'.$key, ['order' => $order->order_number] + $extra),
        );
    }
}
