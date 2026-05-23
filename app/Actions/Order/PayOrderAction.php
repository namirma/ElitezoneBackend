<?php

namespace App\Actions\Order;

use App\Events\Order\OrderPaid;
use App\Exceptions\Order\OrderNotPayableException;
use App\Models\Order;
use App\Models\User;
use App\Services\Payment\PaymentGatewayFactory;
use Illuminate\Support\Facades\DB;

class PayOrderAction
{
    public function __construct(
        private readonly PaymentGatewayFactory $gatewayFactory
    ) {}

    /**
     * پردازش پرداخت سفارش از طریق گیت‌وی مشخص شده
     */
    public function execute(User $user, Order $order, string $gatewayName): Order
    {
        $gateway = $this->gatewayFactory->make($gatewayName);
        $updatedOrder = DB::transaction(function () use ($user, $order, $gateway, $gatewayName) {

            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->first();

            if ($lockedOrder->placer_user_id !== $user->id) {
                throw new OrderNotPayableException(__('orders.errors.not_owner'));
            }

            $payableStatuses = [Order::STATUS_PENDING, Order::STATUS_RESERVED_EXTENDED];
            if (! in_array($lockedOrder->status, $payableStatuses, true)) {
                throw new OrderNotPayableException(__("orders.status.{$lockedOrder->status}"));
            }

            if ($lockedOrder->payment_status === Order::PAYMENT_PAID) {
                throw new OrderNotPayableException(__('orders.errors.already_paid'));
            }

            $result = $gateway->pay($user, $lockedOrder);

            $lockedOrder->update([
                Order::PAYMENT_METHOD => $gatewayName,
                'payment_reference' => $result['reference_id'] ?? null,
            ]);

            return $lockedOrder->fresh();
        }, 3);

        OrderPaid::dispatch($updatedOrder);

        return $updatedOrder;
    }
}
