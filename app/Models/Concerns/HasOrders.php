<?php

namespace App\Models\Concerns;

use App\Models\Order;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait HasOrders
{
    /**
     * سفارش‌هایی که کاربر ثبت کرده (خریدار)
     */
    public function placedOrders(): HasMany
    {
        return $this->hasMany(Order::class, Order::PLACER_USER_ID);
    }

    /**
     * سفارش‌هایی که کاربر ذی‌نفع است (مثلاً برایش خریده‌اند)
     */
    public function beneficiaryOrders(): HasMany
    {
        return $this->hasMany(Order::class, Order::BENEFICIARY_USER_ID);
    }

}
