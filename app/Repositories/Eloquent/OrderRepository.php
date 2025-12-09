<?php

namespace App\Repositories\Eloquent;

use App\Models\Order;
use App\Models\User; 
use App\Repositories\Contracts\OrderRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OrderRepository implements OrderRepositoryInterface
{
    public function getUserOrders(int $userId, string $roleType, string $statusGroup, int $perPage = 10): LengthAwarePaginator
    {
        $query = Order::query()
            ->with(['items.productVariant', 'items.supplier.profile', 'beneficiary.profile', 'placer.profile']);

        // ۱. فیلتر بر اساس نقش (برای خودم یا برای دیگران)
        if ($roleType === 'myself') {
            $query->where('placer_user_id', $userId)
                  ->where(fn($q) => $q->whereNull('beneficiary_user_id')->orWhere('beneficiary_user_id', $userId));
        } elseif ($roleType === 'others') {
            $query->where('placer_user_id', $userId)
                  ->whereNotNull('beneficiary_user_id')
                  ->where('beneficiary_user_id', '!=', $userId);
        }

        match ($statusGroup) {
            'current' => $query->whereIn('status', [
                Order::STATUS_PENDING,
                Order::STATUS_RESERVED_EXTENDED,
                Order::STATUS_PROCESSING,
                Order::STATUS_PREPARING,
                Order::STATUS_WEIGHBRIDGE_DONE,
                Order::STATUS_WAYBILL_ISSUED,
                Order::STATUS_SHIPPED
            ]),
            'delivered' => $query->where('status', Order::STATUS_DELIVERED),
            'cancelled' => $query->whereIn('status', [Order::STATUS_CANCELLED, Order::STATUS_FAILED]),
            default => null
        };

        return $query->latest()->paginate($perPage);
    }

    public function findById(int $id): ?Order
    {
        return Order::with([
            'items.productVariant',
            'items.supplier.profile',
            'address',
            'placer.profile',
            'beneficiary.profile'
        ])->find($id);
    }
}
