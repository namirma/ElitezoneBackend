<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\PayHoldFeeRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Http\Resources\OrderResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    // =========================================================================
    //  بخش ۱: لیست‌های فیلتر شده (داشبورد کاربر)
    // =========================================================================

    public function listMyCurrentOrders(Request $request): JsonResponse
    {
        return $this->getOrdersByStatus($request, 'myself', 'current');
    }

    public function listMyDeliveredOrders(Request $request): JsonResponse
    {
        return $this->getOrdersByStatus($request, 'myself', 'delivered');
    }

    public function listMyCancelledOrders(Request $request): JsonResponse
    {
        return $this->getOrdersByStatus($request, 'myself', 'cancelled');
    }

    public function listOthersCurrentOrders(Request $request): JsonResponse
    {
        return $this->getOrdersByStatus($request, 'others', 'current');
    }

    public function listOthersDeliveredOrders(Request $request): JsonResponse
    {
        return $this->getOrdersByStatus($request, 'others', 'delivered');
    }

    public function listOthersCancelledOrders(Request $request): JsonResponse
    {
        return $this->getOrdersByStatus($request, 'others', 'cancelled');
    }

    /**
     * موتور جستجو و فیلتر مرکزی برای تب‌های سفارشات داشبورد
     */
    private function getOrdersByStatus(Request $request, string $type, string $tabStatus): JsonResponse
    {
        $userId = Auth::id();
        $query = Order::query()->with([
            'items.productVariant',
            'items.supplier.profile',
            'beneficiary.profile',
            'placer.profile'
        ]);

        // فیلتر بر اساس مالکیت (خودم / دیگران)
        if ($type === 'myself') {
            $query->where(Order::PLACER_USER_ID, $userId)
                ->where(function ($q) use ($userId) {
                    $q->whereNull(Order::BENEFICIARY_USER_ID)
                        ->orWhere(Order::BENEFICIARY_USER_ID, $userId);
                });
        } elseif ($type === 'others') {
            $query->where(Order::PLACER_USER_ID, $userId)
                ->whereNotNull(Order::BENEFICIARY_USER_ID)
                ->where(Order::BENEFICIARY_USER_ID, '!=', $userId);
        }

        // فیلتر بر اساس وضعیت تب (جاری / تحویل شده / لغو شده)
        match ($tabStatus) {
            'current' => $query->whereIn(Order::STATUS, [
                Order::STATUS_PENDING,
                Order::STATUS_RESERVED_EXTENDED,
                Order::STATUS_PROCESSING,
                Order::STATUS_PREPARING,
                Order::STATUS_WEIGHBRIDGE_DONE,
                Order::STATUS_WAYBILL_ISSUED,
                Order::STATUS_SHIPPED
            ]),
            'delivered' => $query->where(Order::STATUS, Order::STATUS_DELIVERED),
            'cancelled' => $query->whereIn(Order::STATUS, [Order::STATUS_CANCELLED, Order::STATUS_FAILED]),
            default => null
        };

        $orders = $query->latest()->paginate($request->input('per_page', 10));
        return success(OrderResource::collection($orders), __('global.received_successfully'));
    }


    // =========================================================================
    //  بخش ۲: متدهای اصلی و عمومی (CRUD)
    // =========================================================================

    /**
     * لیست کلی سفارشات (با قابلیت فیلتر پیشرفته برای ادمین یا استفاده‌های خاص)
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $viewType = $request->query('view_type', 'my_direct');

        // تنظیم ویوی پیش‌فرض برای تامین‌کننده
        if ($user->hasRole('supplier') && !$request->has('view_type')) {
            $viewType = 'supplier_fulfillment';
        }

        // کنترل دسترسی برای ویوی تامین‌کننده
        if ($viewType === 'supplier_fulfillment' && !$user->hasRole('supplier')) {
            return error(__('auth.forbidden'), [], 403);
        }

        $query = Order::query()->latest();
        $this->applyEagerLoading($query, $user, $viewType);
        $this->applyViewFilters($query, $user->id, $viewType);
        $this->applyStatusFilters($query, $request->query('status_type'));

        $orders = $query->paginate($request->query('per_page', 10));
        return success(OrderResource::collection($orders), __('global.received_successfully'));
    }

    /**
     * مشاهده جزئیات یک سفارش
     */
    public function show(Order $order): JsonResponse
    {
        $user = Auth::user();

        // بررسی دسترسی (خریدار، ذی‌نفع یا تامین‌کننده مرتبط)
        $isSupplier = $user->hasRole('supplier') && $order->items()->where(OrderItem::SUPPLIER_USER_ID, $user->id)->exists();
        $isOwner = $order->{Order::PLACER_USER_ID} === $user->id || $order->{Order::BENEFICIARY_USER_ID} === $user->id;

        if (!$isOwner && !$isSupplier) {
            return error(__('auth.forbidden'), [], 403);
        }

        $order->load([
            'items' => function ($q) use ($user, $isSupplier) {
                if ($isSupplier) {
                    $q->where(OrderItem::SUPPLIER_USER_ID, $user->id);
                }
                $q->with(['productVariant', 'supplier.profile']);
            },
            'address',
            'placer.profile',
            'beneficiary.profile'
        ]);

        return success(new OrderResource($order), __('global.received_successfully'));
    }

    /**
     * لغو سفارش توسط کاربر
     */
    public function cancel(Order $order): JsonResponse
    {
        $userId = Auth::id();

        if ($order->{Order::PLACER_USER_ID} !== $userId && $order->{Order::BENEFICIARY_USER_ID} !== $userId) {
            return error(__('auth.forbidden'), [], 403);
        }

        $cancellable = [
            Order::STATUS_PENDING,
            Order::STATUS_RESERVED_EXTENDED,
            Order::STATUS_PROCESSING,
            Order::STATUS_PREPARING,
            Order::STATUS_WEIGHBRIDGE_DONE,
            Order::STATUS_WAYBILL_ISSUED
        ];

        if (!in_array($order->{Order::STATUS}, $cancellable)) {
            return error(__('orders.cannot_cancel'), [], 400);
        }

        // تعیین وضعیت پرداخت پس از لغو
        $newPaymentStatus = match ($order->{Order::PAYMENT_STATUS}) {
            Order::PAYMENT_PENDING => Order::STATUS_CANCELLED,
            Order::PAYMENT_PAID    => Order::PAYMENT_REFUND_PENDING, // نیاز به بازگشت وجه
            default                => $order->{Order::PAYMENT_STATUS}
        };

        $order->update([
            Order::STATUS => Order::STATUS_CANCELLED,
            Order::PAYMENT_STATUS => $newPaymentStatus
        ]);

        DB::transaction(function () use ($order) {
            $order->restoreStock();
            $order->refundToWallet();

            $order->update([
                Order::STATUS => Order::STATUS_CANCELLED,
                Order::CANCEL_REASON => 'لغو توسط کاربر',
                Order::PAYMENT_STATUS => $order->payment_status === Order::PAYMENT_PAID
                    ? Order::PAYMENT_REFUNDED
                    : Order::STATUS_CANCELLED
            ]);
        });

        return success(new OrderResource($order->fresh()), __('orders.cancelled_successfully'));
    }


    // =========================================================================
    //  بخش ۳: عملیات مالی و پرداخت (خریدار)
    // =========================================================================

    /**
     * آپلود فیش بانکی (کارت به کارت) توسط خریدار
     */
    public function uploadCustomerReceipt(Request $request, Order $order): JsonResponse
    {
        if ($order->{Order::PLACER_USER_ID} !== Auth::id()) {
            return error(__('auth.forbidden'), [], 403);
        }

        if ($order->{Order::PAYMENT_STATUS} === Order::PAYMENT_PAID) {
            return error(__('orders.already_paid'), [], 400);
        }

        $request->validate([
            'receipt' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $path = $request->file('receipt')->store('order_receipts', 'public');

        $order->update([
            Order::PAYMENT_RECEIPT_REF => $path,
        ]);

        return success(new OrderResource($order), __('orders.receipt_uploaded_success'));
    }

    /**
     * پرداخت هزینه رزرو (تمدید مهلت)
     */
    public function payHoldFee(PayHoldFeeRequest $request, Order $order): JsonResponse
    {
        if ($order->{Order::STATUS} !== Order::STATUS_PENDING) {
            return error(__('orders.cannot_extend_reservation_status'), [], 400);
        }
        if ($order->{Order::RESERVATION_EXPIRES_AT} < now()) {
            return error(__('orders.cannot_extend_reservation_expired'), [], 400);
        }

        // TODO: اتصال به درگاه پرداخت (Podium/Shaparak)
        // فعلاً فرض بر پرداخت موفق است
        $order->update([
            Order::STATUS => Order::STATUS_RESERVED_EXTENDED,
            Order::RESERVATION_EXPIRES_AT => now()->addDay(),
            Order::PARTIAL_PAYMENT_AMOUNT => $request->validated('amount'),
        ]);

        return success(new OrderResource($order->fresh()), __('orders.reservation_extended'));
    }

    /**
     * پرداخت کامل سفارش (مرحله ۲)
     */
    public function payFullOrder(Request $request, Order $order): JsonResponse
    {
        if ($order->{Order::PLACER_USER_ID} !== Auth::id()) {
            return error(__('auth.forbidden'), [], 403);
        }
        if (!in_array($order->{Order::STATUS}, [Order::STATUS_PENDING, Order::STATUS_RESERVED_EXTENDED])) {
            return error(__('orders.cannot_pay_order'), [], 400);
        }

        // TODO: اتصال به درگاه پرداخت کامل
        // شبیه‌سازی پرداخت موفق:
        $order->update([
            Order::STATUS => Order::STATUS_PROCESSING,
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::PAID_AT => now(),
            Order::RESERVATION_EXPIRES_AT => null,
        ]);

        // TODO: ارسال نوتیفیکیشن به تامین‌کننده

        return success(new OrderResource($order), __('orders.payment_successful_awaiting_confirmation'));
    }

    /**
     * دانلود انواع فاکتور (اولیه، نهایی، رسمی)
     */
    public function downloadInvoice(Order $order, string $type): JsonResponse
    {
        $user = Auth::user();
        $isSupplier = $user->hasRole('supplier') && $order->items()->where(OrderItem::SUPPLIER_USER_ID, $user->id)->exists();
        $isOwner = $order->{Order::PLACER_USER_ID} === $user->id || $order->{Order::BENEFICIARY_USER_ID} === $user->id;

        if (!$isOwner && !$isSupplier) {
            return error(__('auth.forbidden'), [], 403);
        }

        $ref = match ($type) {
            'initial' => $order->{Order::INITIAL_INVOICE_REF},
            'final'   => $order->{Order::FINAL_INVOICE_REF},
            'tax'     => $order->{Order::TAX_INVOICE_REF},
            default   => null,
        };

        if (!$ref) {
            return error(__('orders.invoice_not_found'), [], 404);
        }

        // تولید لینک دانلود موقت یا واقعی
        return success(['download_link' => url("/invoices/placeholder/{$ref}")], __('orders.invoice_download_link'));
    }


    // =========================================================================
    //  بخش ۴: عملیات تامین‌کننده (Supplier Actions)
    // =========================================================================

    public function markAsPrepared(Order $order): JsonResponse
    {
        $this->authorizeSupplier($order);

        if ($order->{Order::STATUS} === Order::STATUS_PROCESSING) {
            $order->update([
                Order::STATUS => Order::STATUS_PREPARING,
                Order::PREPARED_AT => now()
            ]);
            return success(new OrderResource($order), __('orders.marked_as_prepared'));
        }
        return error(__('orders.cannot_prepare_in_current_state'), [], 400);
    }

    public function addWaybill(Request $request, Order $order): JsonResponse
    {
        $this->authorizeSupplier($order);
        $request->validate([Order::WAYBILL_REF => 'required|string|max:255']);

        if (in_array($order->{Order::STATUS}, [Order::STATUS_PREPARING, Order::STATUS_WEIGHBRIDGE_DONE])) {
            $order->update([
                Order::STATUS => Order::STATUS_WAYBILL_ISSUED,
                Order::WAYBILL_REF => $request->input(Order::WAYBILL_REF),
                Order::SHIPPED_AT => now()
            ]);
            return success(new OrderResource($order), __('orders.waybill_added'));
        }
        return error(__('orders.cannot_add_waybill'), [], 400);
    }

    public function issueEspadanaCommand(Order $order): JsonResponse
    {
        $this->authorizeSupplier($order);
        // TODO: اتصال به وب‌سرویس اسپادانا
        $order->update([Order::ESPADANA_ORDER_REF => 'ESPADANA_REF_' . $order->id . '_' . time()]);
        return success(new OrderResource($order), __('orders.espadana_command_issued'));
    }

    public function attachPaymentReceipt(Request $request, Order $order): JsonResponse
    {
        $this->authorizeSupplier($order);
        // این متد مخصوص تامین‌کننده است (مثلاً رسید پرداخت داخلی)
        $request->validate(['receipt_ref' => 'required|string|max:255']);
        $order->update([Order::PAYMENT_RECEIPT_REF => $request->input('receipt_ref')]);
        return success(new OrderResource($order), __('orders.receipt_attached'));
    }


    // =========================================================================
    //  بخش ۵: توابع کمکی و خصوصی (Helpers)
    // =========================================================================

    private function applyEagerLoading(Builder $query, User $user, string $viewType): void
    {
        $query->with([
            'items' => function ($q) use ($viewType, $user) {
                if ($viewType === 'supplier_fulfillment') {
                    $q->where(OrderItem::SUPPLIER_USER_ID, $user->id);
                }
                $q->with(['productVariant', 'supplier.profile']);
            },
            'address',
            'placer.profile',
            'beneficiary.profile'
        ]);
    }

    private function applyViewFilters(Builder $query, int $userId, string $viewType): void
    {
        match ($viewType) {
            'supplier_fulfillment' => $query->whereHas('items', fn($q) => $q->where(OrderItem::SUPPLIER_USER_ID, $userId)),
            'pending_surveys' => $query->where(fn($q) => $q->where(Order::PLACER_USER_ID, $userId)->orWhere(Order::BENEFICIARY_USER_ID, $userId))
                ->where(Order::STATUS, Order::STATUS_DELIVERED)
                ->where(Order::SURVEY_STATUS, '!=', Order::SURVEY_COMPLETED),
            'my_direct' => $query->where(Order::PLACER_USER_ID, $userId)
                ->where(fn($q) => $q->whereNull(Order::BENEFICIARY_USER_ID)->orWhere(Order::BENEFICIARY_USER_ID, $userId)),
            'for_others' => $query->where(Order::PLACER_USER_ID, $userId)
                ->whereNotNull(Order::BENEFICIARY_USER_ID)
                ->where(Order::BENEFICIARY_USER_ID, '!=', $userId),
            'employer_associated' => $query->where(Order::BENEFICIARY_USER_ID, $userId)
                ->where(Order::PLACER_USER_ID, '!=', $userId),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function applyStatusFilters(Builder $query, ?string $statusType): void
    {
        if ($statusType === 'history') {
            $query->whereIn(Order::STATUS, [Order::STATUS_DELIVERED, Order::STATUS_CANCELLED, Order::STATUS_FAILED]);
        } else {
            $query->whereIn(Order::STATUS, [
                Order::STATUS_PENDING,
                Order::STATUS_RESERVED_EXTENDED,
                Order::STATUS_PROCESSING,
                Order::STATUS_PREPARING,
                Order::STATUS_WEIGHBRIDGE_DONE,
                Order::STATUS_WAYBILL_ISSUED,
                Order::STATUS_SHIPPED
            ]);
        }
    }

    private function authorizeSupplier(Order $order): void
    {
        $user = Auth::user();
        if (!$user->hasRole('supplier') || !$order->items()->where(OrderItem::SUPPLIER_USER_ID, $user->id)->exists()) {
            abort(error(__('auth.forbidden'), [], 403));
        }
    }
}
