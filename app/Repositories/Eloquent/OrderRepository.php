<?php

namespace App\Repositories\Eloquent;

use App\Models\Order;
use App\Repositories\Contracts\OrderRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class OrderRepository implements OrderRepositoryInterface
{
    /**
     * پیاده‌سازی متد جدید create برای PlaceOrderAction
     */
    public function create(array $data): Order
    {
        return Order::create($data);
    }

    /**
     * پیاده‌سازی متد قدیمی findById
     */
    public function findById(int $id): ?Order
    {
        return Order::with(['items', 'address', 'placer', 'beneficiary'])->find($id);
    }

    /**
     * پیاده‌سازی متد قدیمی getUserOrders (اصلاح شده)
     */
    public function getUserOrders(int $userId, string $roleType, string $statusGroup, int $perPage = 10): LengthAwarePaginator
    {
        return Order::query()
            ->where(function (Builder $query) use ($userId, $roleType) {
                if ($roleType === 'beneficiary') {
                    $query->where(Order::BENEFICIARY_USER_ID, $userId);
                } else {
                    $query->where(Order::PLACER_USER_ID, $userId)
                          ->whereNull(Order::BENEFICIARY_USER_ID);
                }
            })
            // فیلتر بر اساس گروه‌های وضعیت (جاری، تحویل شده، لغو شده)
            ->when($statusGroup === 'current', function (Builder $q) {
                $q->whereIn(Order::STATUS, [
                    'pending',
                    'processing',
                    'preparing',
                    'weighbridge_done',
                    'waybill_issued',
                    'shipped'
                ]);
            })
            ->when($statusGroup === 'delivered', function (Builder $q) {
                $q->where(Order::STATUS, 'delivered');
            })
            ->when($statusGroup === 'cancelled', function (Builder $q) {
                $q->where(Order::STATUS, 'cancelled');
            })
            ->latest()
            ->paginate($perPage);
    }

    public function update(Order $order, array $data): bool
    {
        return $order->update($data);
    }
}
