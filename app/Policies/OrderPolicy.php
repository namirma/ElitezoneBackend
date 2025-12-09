<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class OrderPolicy
{
    use HandlesAuthorization;

    public function view(User $user, Order $order): bool
    {
        // ۱. مالک یا ذی‌نفع
        if ($order->placer_user_id === $user->id || $order->beneficiary_user_id === $user->id) {
            return true;
        }

        // ۲. تامین‌کننده مرتبط
        if ($user->hasRole('supplier')) {
            return $order->items()->where('supplier_user_id', $user->id)->exists();
        }

        return false;
    }

    public function pay(User $user, Order $order): bool
    {
        return $order->placer_user_id === $user->id;
    }

    public function cancel(User $user, Order $order): bool
    {
        return $order->placer_user_id === $user->id || $order->beneficiary_user_id === $user->id;
    }

    public function manageAsSupplier(User $user, Order $order): bool
    {
        if (!$user->hasRole('supplier')) {
            return false;
        }
        return $order->items()->where('supplier_user_id', $user->id)->exists();
    }
}
