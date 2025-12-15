<?php

namespace App\Actions\Order;

use App\Events\OrderPaid;
use App\Exceptions\Order\InvalidOrderStateException;
use App\Exceptions\Order\OrderNotPayableException;
use App\Exceptions\Wallet\InsufficientBalanceException;
use App\Models\Order;
use App\Models\User;
use App\Repositories\Contracts\OrderRepositoryInterface;
use App\Services\Payment\PaymentGatewayFactory;
use Illuminate\Support\Facades\DB;
use Exception;

class PayOrderAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepo,
        private readonly PaymentGatewayFactory $gatewayFactory 
    ) {}

    /**
     * پردازش پرداخت سفارش
     */
    public function execute(User $user, Order $order, string $gatewayName): Order
    {
        $payableStatuses = [Order::STATUS_PENDING, Order::STATUS_RESERVED_EXTENDED];

        // 1. گاردها
        if (!in_array($order->status, $payableStatuses)) {
            throw new OrderNotPayableException(__("orders.status.{$order->status}"));
        }

        if ($order->payment_status === Order::PAYMENT_PAID) {
            throw new OrderNotPayableException(__('orders.errors.already_paid'));
        }

        // 2. انتخاب استراتژی پرداخت
        $gateway = $this->gatewayFactory->make($gatewayName);

        // 3. اجرای تراکنش اتمیک
        $updatedOrder = DB::transaction(function () use ($user, $order, $gateway, $gatewayName) {

            // الف) انجام عملیات پرداخت (پلی‌مورفیسم)
            $result = $gateway->pay($user, $order);

            // ب) آپدیت وضعیت سفارش
            $this->orderRepo->update($order, [
                Order::STATUS         => Order::STATUS_PROCESSING,
                Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
                Order::PAYMENT_METHOD => $gatewayName,
                Order::PAID_AT        => now(),
            ]);

            return $order;
        });

        OrderPaid::dispatch($updatedOrder);

        return $updatedOrder;
    }

    /**
     * منطق پرداخت با کیف پول
     */
    private function payWithWallet(User $user, Order $order): void
    {
        $wallet = $user->wallet;

        if (!$wallet) {
            throw new Exception("کیف پول برای این کاربر فعال نشده است.");
        }

        if ($wallet->balance < $order->final_amount) {
            throw new InsufficientBalanceException();
        }

        $wallet->decrement('balance', $order->final_amount);

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
