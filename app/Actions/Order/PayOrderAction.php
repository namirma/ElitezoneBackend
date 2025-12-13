<?php

namespace App\Actions\Order;

use App\Events\OrderPaid;
use App\Exceptions\Order\InvalidOrderStateException;
use App\Exceptions\Order\OrderNotPayableException;
use App\Exceptions\Wallet\InsufficientBalanceException;
use App\Models\Order;
use App\Models\User;
use App\Repositories\Contracts\OrderRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Exception;

class PayOrderAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepo
    ) {}

    /**
     * پردازش پرداخت سفارش
     *
     * @param User $user
     * @param Order $order
     * @param string $gateway
     * @return Order
     * @throws \App\Exceptions\BusinessException
     */
    public function execute(User $user, Order $order, string $gateway): Order
    {
        $payableStatuses = [Order::STATUS_PENDING, Order::STATUS_RESERVED_EXTENDED];

        if (!in_array($order->status, $payableStatuses)) {
            throw new OrderNotPayableException(__("orders.status.{$order->status}"));
        }

        // ۲. گارد: آیا قبلاً پرداخت شده؟
        if ($order->payment_status === Order::PAYMENT_PAID) {
            throw new InvalidOrderStateException('پرداخت شده', 'پرداخت مجدد');
        }

        // ۳. اجرای تراکنش اتمیک
        $updatedOrder = DB::transaction(function () use ($user, $order, $gateway) {

            // الف) کسر مبلغ (Logic Strategy)
            if ($gateway === 'wallet') {
                $this->payWithWallet($user, $order);
            } else {
                throw new Exception("درگاه $gateway هنوز پیاده‌سازی نشده است.");
            }

            $this->orderRepo->update($order, [
                Order::STATUS         => Order::STATUS_PROCESSING,
                Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
                Order::PAYMENT_METHOD => $gateway,
                Order::PAID_AT        => now(),
            ]);

            return $order;
        });

        // ۴. شلیک رویداد
        OrderPaid::dispatch($updatedOrder);

        return $updatedOrder;
    }

    /**
     * منطق پرداخت با کیف پول
     */
    /**
     * منطق پرداخت با کیف پول
     */
    private function payWithWallet(User $user, Order $order): void
    {
        // 1. دریافت کیف پول
        $wallet = $user->wallet;

        if (!$wallet) {
            throw new Exception("کیف پول برای این کاربر فعال نشده است.");
        }

        // 2. بررسی موجودی
        if ($wallet->balance < $order->final_amount) {
            throw new InsufficientBalanceException();
        }

        // 3. کسر موجودی
        $wallet->decrement('balance', $order->final_amount);

        // 4. ثبت تراکنش
        $wallet->transactions()->create([
            'amount'         => -1 * $order->final_amount,
            'type'           => 'withdraw',
            'status'         => 'confirmed',
            'description'    => "پرداخت سفارش شماره {$order->order_number}",
            'meta'           => ['order_id' => $order->id],
            'balance_before' => $wallet->balance + $order->final_amount,
            'balance_after'  => $wallet->balance,
        ]);
    }
}
