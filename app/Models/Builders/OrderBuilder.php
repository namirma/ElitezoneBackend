<?php

namespace App\Models\Builders;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

class OrderBuilder extends Builder
{
    /**
     * فقط سفارش‌های فعال (جاری)
     */
    public function current(): self
    {
        return $this->whereIn(Order::STATUS, [
            Order::STATUS_PENDING,
            Order::STATUS_RESERVED_EXTENDED,
            Order::STATUS_PROCESSING,
            Order::STATUS_PREPARING,
            Order::STATUS_WEIGHBRIDGE_DONE,
            Order::STATUS_WAYBILL_ISSUED,
            Order::STATUS_SHIPPED
        ]);
    }

    /**
     * فقط سفارش‌های پرداخت شده
     */
    public function paid(): self
    {
        return $this->where(Order::PAYMENT_STATUS, Order::PAYMENT_PAID);
    }

    /**
     * سفارش‌های مربوط به یک کاربر (چه خریدار، چه ذی‌نفع)
     */
    public function forUser(int $userId): self
    {
        return $this->where(function ($q) use ($userId) {
            $q->where(Order::PLACER_USER_ID, $userId)
              ->orWhere(Order::BENEFICIARY_USER_ID, $userId);
        });
    }

    /**
     * سفارش‌های منقضی شده (جهت جاب‌های سیستمی)
     */
    public function expired(): self
    {
        return $this->where(Order::STATUS, Order::STATUS_PENDING)
                    ->where(Order::RESERVATION_EXPIRES_AT, '<', now());
    }

    /**
     * سفارش‌های مربوط به یک تامین‌کننده خاص
     * (با استفاده از Relationship)
     */
    public function forSupplier(int $supplierId): self
    {
        return $this->whereHas('items', function ($q) use ($supplierId) {
            $q->where('supplier_user_id', $supplierId);
        });
    }
}
