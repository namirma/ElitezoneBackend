<?php

namespace App\Repositories\Eloquent;

use App\Models\Order;
use App\Repositories\Contracts\OrderRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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
            // Single source of truth for eager-loads — same set OrderResource renders.
            // (Previously omitted `eliteYAR`, causing an N+1 when the resource read it.)
            ->with(Order::resourceRelations())
            // «سفارش‌های من» — تعریف و تاریخچه‌اش در `OrderBuilder::buyerSideOf()`.
            ->when(
                $roleType === 'beneficiary',
                fn ($q) => $q->where(Order::BENEFICIARY_USER_ID, $userId),
                fn ($q) => $q->buyerSideOf($userId),
            )
            ->inStatusGroup($statusGroup)
            ->latest()
            ->paginate($perPage);
    }

    public function update(Order $order, array $data): bool
    {
        return $order->update($data);
    }
}
