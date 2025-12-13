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
     * پیاده‌سازی متد قدیمی getUserOrders (بهینه شده)
     * این متد دقیقاً همان کاری را می‌کند که قبلاً در کنترلر انجام می‌شد، اما تمیزتر.
     */
    public function getUserOrders(int $userId, string $roleType, string $statusGroup, int $perPage = 10): LengthAwarePaginator
    {
        return Order::query()
            // فیلتر بر اساس کاربر
            ->where(function (Builder $query) use ($userId, $roleType) {
                if ($roleType === 'beneficiary') {
                    // تب "خرید برای دیگران" (من ذی‌نفع هستم)
                    $query->where(Order::BENEFICIARY_USER_ID, $userId)
                        ->whereColumn(Order::PLACER_USER_ID, '!=', Order::BENEFICIARY_USER_ID);
                } else {
                    // تب "خرید برای خودم" (من ثبت کننده‌ام)
                    $query->where(Order::PLACER_USER_ID, $userId);
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
