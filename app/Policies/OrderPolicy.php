<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class OrderPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return null;
    }

    /**
     * مشاهده جزئیات سفارش
     */
    public function view(User $user, Order $order): Response
    {
        // ۱. مالک یا ذی‌نفع
        if ($order->placer_user_id === $user->id || $order->beneficiary_user_id === $user->id) {
            return Response::allow();
        }
        if ($user->hasRole('supplier')) {
            $isSupplier = $order->items()->where('supplier_user_id', $user->id)->exists();

            return $isSupplier
                ? Response::allow()
                : Response::deny(__('auth.policy.supplier_access_denied'));
        }

        return Response::deny(__('auth.policy.order_view_denied'));
    }

    /**
     * پرداخت سفارش
     */
    public function pay(User $user, Order $order): Response
    {
        // آیا سفارش پرداخت شده است؟
        if ($order->payment_status === Order::PAYMENT_PAID) {
            return Response::deny(__('checkout.order_already_paid'));
        }

        // فقط ثبت‌کننده یا ذی‌نفع می‌تواند پرداخت کند
        return ($order->placer_user_id === $user->id || $order->beneficiary_user_id === $user->id)
            ? Response::allow()
            : Response::deny(__('auth.policy.only_placer_can_pay'));
    }

    /**
     * لغو سفارش
     */
    public function cancel(User $user, Order $order): Response
    {
        // سفارشات تکمیل شده یا ارسال شده قابل لغو نیستند (بسته به بیزنس شما)
        if (in_array($order->status, [Order::STATUS_COMPLETED, Order::STATUS_SHIPPED])) {
            return Response::deny(__('orders.cannot_cancel_shipped_order'));
        }

        return ($order->placer_user_id === $user->id || $order->beneficiary_user_id === $user->id)
            ? Response::allow()
            : Response::deny(__('auth.policy.cancel_denied'));
    }

    /**
     * مدیریت سفارش توسط تامین‌کننده (مثلاً تغییر وضعیت آیتم‌ها)
     */
    public function manageAsSupplier(User $user, Order $order): Response
    {
        if (!$user->hasRole('supplier')) {
            return Response::deny(__('auth.policy.not_supplier'));
        }

        return $order->items()->where('supplier_user_id', $user->id)->exists()
            ? Response::allow()
            : Response::deny(__('auth.policy.supplier_item_mismatch'));
    }

    /**
     * ثبت فاکتور نهایی سفارش
     */
    public function finalizeInvoice(User $user, Order $order): Response
    {
        // فقط ثبت‌کننده یا ذی‌نفع اجازه دارند فاکتور نهایی را ثبت کنند
        return ($order->placer_user_id === $user->id || $order->beneficiary_user_id === $user->id)
            ? Response::allow()
            : Response::deny(__('auth.policy.finalize_invoice_denied', ['default' => 'شما دسترسی لازم برای این عملیات را ندارید.']));
    }
}
