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
            // Single source of truth for eager-loads — same set OrderResource renders.
            // (Previously omitted `eliteYAR`, causing an N+1 when the resource read it.)
            ->with(Order::resourceRelations())
            ->where(function (Builder $query) use ($userId, $roleType) {
                if ($roleType === 'beneficiary') {
                    $query->where(Order::BENEFICIARY_USER_ID, $userId);
                } else {
                    $query->where(Order::PLACER_USER_ID, $userId)
                        ->where(function ($q) use ($userId) {
                            $q->whereNull(Order::BENEFICIARY_USER_ID)
                                ->orWhere(Order::BENEFICIARY_USER_ID, $userId);
                        });
                }
            })
            // فیلتر بر اساس گروه‌های وضعیت — از OrderBuilder scopes استفاده می‌شود
            ->when($statusGroup === 'current', fn ($q) => $q->current())
            ->when($statusGroup === 'delivered', fn ($q) => $q->delivered())
            ->when($statusGroup === 'cancelled', fn ($q) => $q->cancelled())
            ->latest()
            ->paginate($perPage);
    }

    public function update(Order $order, array $data): bool
    {
        return $order->update($data);
    }
}
