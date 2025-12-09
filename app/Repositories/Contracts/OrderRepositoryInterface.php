<?php

namespace App\Repositories\Contracts;

use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface OrderRepositoryInterface
{
    /**
     * دریافت لیست سفارش‌های یک کاربر بر اساس وضعیت
     */
    public function getUserOrders(int $userId, string $roleType, string $statusGroup, int $perPage = 10): LengthAwarePaginator;

    /**
     * یافتن یک سفارش با روابط کامل
     */
    public function findById(int $id): ?Order;
}
