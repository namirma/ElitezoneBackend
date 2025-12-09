<?php

namespace App\Actions\Order;

use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Exception;

class PayOrderAction
{
    public function __construct(
        private readonly InventoryService $inventoryService
    ) {}

    public function execute(User $user, Order $order, string $gateway): Order
    {
        if ($gateway === 'wallet') {
            return $this->payWithWallet($user, $order);
        }

        throw new Exception("درگاه $gateway پشتیبانی نمی‌شود.");
    }

    private function payWithWallet(User $user, Order $order): Order
    {
        return DB::transaction(function () use ($user, $order) {
            $wallet = $user->wallet()->lockForUpdate()->firstOrCreate();

            if ($wallet->balance < $order->final_amount) {
                throw new Exception(__('orders.wallet_balance_low'));
            }

            // کسر انبار
            $this->inventoryService->deductStockForOrder($order);

            // کسر پول
            $wallet->decrement('balance', $order->final_amount);

            // ثبت تراکنش
            $wallet->transactions()->create([
                'amount'      => $order->final_amount,
                'type'        => 'withdraw',
                'status'      => 'confirmed',
                'description' => "پرداخت سفارش #{$order->order_number}",
                'order_id'    => $order->id
            ]);

            $order->update([
                Order::STATUS => Order::STATUS_PROCESSING,
                Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
                Order::PAID_AT => now(),
                Order::RESERVATION_EXPIRES_AT => null,
            ]);

            OrderPaid::dispatch($order);

            return $order;
        });
    }
}
