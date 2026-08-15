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

        // ۲. الیت یارِ سفارش — کسی که کمیسیون WEY به او تعلق دارد.
        //
        // این شرط جا افتاده بود و در یکی از دو سناریوی معمول کاملاً می‌شکست: وقتی
        // **کارفرما** سفارش می‌دهد و الیت یار را نام می‌برد، `beneficiary_user_id`
        // ممنوع است (StoreOrderRequest) و الیت یار نه ثبت‌کننده است نه ذی‌نفع — پس
        // سفارشی را که روی آن کمیسیون می‌گیرد اصلاً نمی‌توانست ببیند.
        //
        // تناقضش هم آشکار بود: `submitEliteYARMoadianInvoice` (سند Q) گاردِ خودش را
        // مستقیم روی `elite_yar_user_id` دارد، یعنی همین کاربر مجاز است هولدِ
        // کمیسیونِ خودش را آزاد کند ولی مجاز نبود سفارش یا سندش را ببیند.
        // getAttribute و نه دسترسی جادویی: larastan بدون فایل‌های ide-helper این
        // ستون را نمی‌شناسد و level 5 خطا می‌دهد.
        $eliteYarUserId = $order->getAttribute(Order::ELITE_YAR_USER_ID);

        if ($eliteYarUserId !== null && (int) $eliteYarUserId === (int) $user->id) {
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
        // خودلغویِ خریدار فعلاً هیچ وضعیتی ندارد — فاز ۴.
        //
        // تنها وضعیتِ خودلغو `pending` بود («هنوز پولی جابه‌جا نشده») و آن وضعیت
        // حذف شد: سفارش تنها پس از نشستنِ پول ساخته می‌شود. وضعیت‌های پس از
        // پرداخت از مسیر مرجوعی/داوری می‌روند، نه لغو مستقیم.
        //
        // پنجره‌ی لغوِ خریدار روی رزرو (بین `reserve_deadline_original` و
        // `reserve_deadline`، بند ۳.۴) در فاز ۵ باز می‌شود؛ آن‌وقت این متد شرطِ
        // مالکیتِ زیر را دوباره لازم دارد. ادمین از `before()` رد می‌شود.
        return Response::deny(__('orders.cannot_cancel'));
    }

    /**
     * مدیریت سفارش توسط تامین‌کننده (مثلاً تغییر وضعیت آیتم‌ها)
     */
    public function manageAsSupplier(User $user, Order $order): Response
    {
        if (! $user->hasRole('supplier')) {
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
