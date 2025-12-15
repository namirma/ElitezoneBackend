<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class OrderPolicy
{
    use HandlesAuthorization;

    public function view(User $user, Order $order): Response
    {
        // ۱. مالک یا ذی‌نفع
        if ($order->placer_user_id === $user->id || $order->beneficiary_user_id === $user->id) {
            return Response::allow();
        }

        // ۲. تامین‌کننده مرتبط
        if ($user->hasRole('supplier')) {
            $isSupplier = $order->items()->where('supplier_user_id', $user->id)->exists();
            return $isSupplier
                ? Response::allow()
                : Response::deny(__('auth.policy.supplier_access_denied'));
        }

        // ۳. ادمین (معمولاً ادمین دسترسی دارد)
        if ($user->hasRole('admin')) {
             return Response::allow();
        }

        return Response::deny(__('auth.policy.order_view_denied'));
    }

    public function pay(User $user, Order $order): Response
    {
        // فقط ثبت‌کننده سفارش می‌تواند پرداخت کند
        return $order->placer_user_id === $user->id
            ? Response::allow()
            : Response::deny(__('auth.policy.only_placer_can_pay'));
    }

    public function cancel(User $user, Order $order): Response
    {
        return ($order->placer_user_id === $user->id || $order->beneficiary_user_id === $user->id)
            ? Response::allow()
            : Response::deny(__('auth.policy.cancel_denied'));
    }

    public function manageAsSupplier(User $user, Order $order): Response
    {
        if (!$user->hasRole('supplier')) {
            return Response::deny(__('auth.policy.not_supplier'));
        }

        return $order->items()->where('supplier_user_id', $user->id)->exists()
            ? Response::allow()
            : Response::deny(__('auth.policy.supplier_item_mismatch'));
    }
}
