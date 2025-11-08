<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\OrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;


class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $user = Auth::user();
        $userId = $user->id;
        $viewType = $request->query('view_type', 'my_direct');

        // مدیریت view_type پیش‌فرض بر اساس نقش
        if (!in_array($viewType, ['my_direct', 'for_others', 'employer_associated', 'supplier_fulfillment'])) {
            if ($user->hasRole('supplier')) {
                $viewType = 'supplier_fulfillment';
            } else {
                $viewType = 'my_direct'; // پیش‌فرض برای کارشناس/کارفرما
            }
        }

        // --- شروع اصلاح: چک کردن دسترسی (Authorization) ---
        if ($viewType === 'supplier_fulfillment' && !$user->hasRole('supplier')) {
            // اگر کاربر تامین‌کننده نیست ولی درخواست ویوی تامین‌کننده داده، خطا بده
            return error(__('auth.forbidden'), [], 403);
        }

        // اگر کاربر تامین‌کننده است، نباید به ویوهای دیگر دسترسی داشته باشد
        if ($user->hasRole('supplier') && $viewType !== 'supplier_fulfillment') {
            return error(__('auth.forbidden'), [], 403);
        }
        // --- پایان اصلاح ---

        // ... (ادامه کد: تعریف $currentStatuses و $historyStatuses) ...
        $currentStatuses = [
            Order::STATUS_PENDING,
            Order::STATUS_PROCESSING,
            Order::STATUS_PREPARING,
            Order::STATUS_WEIGHBRIDGE_DONE,
            Order::STATUS_WAYBILL_ISSUED,
            Order::STATUS_SHIPPED,
        ];
        $historyStatuses = [
            Order::STATUS_DELIVERED,
            Order::STATUS_CANCELLED,
            Order::STATUS_FAILED,
        ];


        $query = Order::query()->with([
            'items' => function ($itemQuery) use ($viewType, $userId) {
                if ($viewType === 'supplier_fulfillment') {
                    // فیلتر کردن آیتم‌ها فقط برای تامین‌کننده
                    $itemQuery->where(OrderItem::SUPPLIER_USER_ID, $userId)->with(['productVariant', 'supplier.profile']);
                } else {
                    // لود کردن همه آیتم‌ها برای کارفرما/کارشناس
                    $itemQuery->with(['productVariant', 'supplier.profile']);
                }
            },
            'address',
            'placer.profile',
            'beneficiary.profile'
        ])->latest();

        // ... (کد switch ($viewType) بدون تغییر) ...
        switch ($viewType) {
            case 'supplier_fulfillment':
                $query->whereHas('items', function ($itemQuery) use ($userId) {
                    $itemQuery->where(OrderItem::SUPPLIER_USER_ID, $userId);
                });
                break;
            case 'my_direct':
                $query->where(Order::PLACER_USER_ID, $userId)
                    ->where(function ($q) use ($userId) {
                        $q->whereNull(Order::BENEFICIARY_USER_ID)
                            ->orWhere(Order::BENEFICIARY_USER_ID, $userId);
                    });
                break;
            case 'for_others':
                $query->where(Order::PLACER_USER_ID, $userId)
                    ->whereNotNull(Order::BENEFICIARY_USER_ID)
                    ->where(Order::BENEFICIARY_USER_ID, '!=', $userId);
                break;
            case 'employer_associated':
                $query->where(Order::BENEFICIARY_USER_ID, $userId)
                    ->where(Order::PLACER_USER_ID, '!=', $userId);
                break;
            default:
                // این حالت نباید رخ دهد چون $viewType در بالا مدیریت شد
                $query->whereRaw('1 = 0'); // برگرداندن لیست خالی
                break;
        }

        if ($request->query('status_type') === 'history') {
            $query->whereIn(Order::STATUS, $historyStatuses);
        } else {
            $query->whereIn(Order::STATUS, $currentStatuses);
        }

        $perPage = $request->query('per_page', 10);
        $orders = $query->paginate($perPage);

        // --- حذف بلاک فیلتر تکراری (رفع خطای IDE) ---
        // if ($viewType === 'supplier_fulfillment') {
        //     $orders->getCollection()->transform( ... ); // <-- این بلاک حذف شود
        // }

        return OrderResource::collection($orders);
    }

    public function show(Order $order): JsonResponse
    {
        $user = Auth::user();
        $userId = $user->id;
        $isSupplierForItem = $user->hasRole('supplier') && $order->items()->where(OrderItem::SUPPLIER_USER_ID, $userId)->exists();

        if ($order->{Order::PLACER_USER_ID} !== $userId && $order->{Order::BENEFICIARY_USER_ID} !== $userId && !$isSupplierForItem) {
            return error(__('auth.forbidden'), [], 403);
        }

        $order->loadMissing([
            'items' => function ($itemQuery) use ($user, $userId) {
                if ($user->hasRole('supplier')) {
                    $itemQuery->where(OrderItem::SUPPLIER_USER_ID, $userId)->with(['productVariant', 'supplier.profile']);
                } else {
                    $itemQuery->with(['productVariant', 'supplier.profile']);
                }
            },
            'address',
            'placer.profile',
            'beneficiary.profile'
        ]);

        if ($user->hasRole('supplier') && $order->items->isEmpty()) {
            return error(__('auth.forbidden'), [], 403);
        }

        return success(new OrderResource($order), __('global.received_successfully'));
    }

    public function cancel(Order $order): JsonResponse
    {
        $user = Auth::user();
        $userId = $user->id;

        // چک دسترسی
        if ($order->{Order::PLACER_USER_ID} !== $userId && $order->{Order::BENEFICIARY_USER_ID} !== $userId) {
            // TODO: Add logic for supplier cancellation if needed
            return error(__('auth.forbidden'), [], 403);
        }

        // وضعیت‌هایی که قابل لغو هستند
        $cancellableStatuses = [
            Order::STATUS_PENDING,
            Order::STATUS_RESERVED_EXTENDED,
            Order::STATUS_PROCESSING,
            Order::STATUS_PREPARING,
            Order::STATUS_WEIGHBRIDGE_DONE,
            Order::STATUS_WAYBILL_ISSUED
        ];

        if (!in_array($order->{Order::STATUS}, $cancellableStatuses)) {
            return error(__('orders.cannot_cancel'), [], 400);
        }

        // --- شروع اصلاح منطق Payment Status ---
        $newPaymentStatus = $order->{Order::PAYMENT_STATUS};

        if ($order->{Order::PAYMENT_STATUS} === Order::PAYMENT_PENDING) {
            // اگر پرداخت نشده بود، وضعیت پرداخت هم لغو می‌شود
            $newPaymentStatus = Order::STATUS_CANCELLED;
        } elseif ($order->{Order::PAYMENT_STATUS} === Order::PAYMENT_PAID) {
            // اگر پرداخت شده بود، باید بازگشت وجه انجام شود
            $newPaymentStatus = Order::PAYMENT_REFUND_PENDING;
            // TODO: اینجا منطق واقعی بازگشت وجه (Refund/Credit) باید فراخوانی شود
        }

        $order->{Order::STATUS} = Order::STATUS_CANCELLED;
        $order->{Order::PAYMENT_STATUS} = $newPaymentStatus; // <-- آپدیت payment_status
        $order->save();
        // --- پایان اصلاح ---

        return success(new OrderResource($order->fresh()), __('orders.cancelled_successfully'));
    }

    public function downloadInvoice(Order $order, string $type): JsonResponse
    {
        $user = Auth::user();
        $userId = $user->id;
        $isSupplierForItem = $user->hasRole('supplier') && $order->items()->where(OrderItem::SUPPLIER_USER_ID, $userId)->exists();
        if ($order->{Order::PLACER_USER_ID} !== $userId && $order->{Order::BENEFICIARY_USER_ID} !== $userId && !$isSupplierForItem) {
            return error(__('auth.forbidden'), [], 403);
        }

        $invoiceRef = match ($type) {
            'initial' => $order->{Order::INITIAL_INVOICE_REF},
            'final' => $order->{Order::FINAL_INVOICE_REF},
            'tax' => $order->{Order::TAX_INVOICE_REF},
            default => null,
        };

        if (!$invoiceRef) {
            if ($type === 'initial' || $type === 'final' || $type === 'tax') {
                return error(__('orders.invoice_not_found'), [], 404);
            } else {
                return error(__('orders.invalid_invoice_type'), [], 400);
            }
        }
        // TODO: Implement actual file finding/generation and download logic
        $downloadLink = url("/invoices/placeholder/{$invoiceRef}");
        return success(['download_link' => $downloadLink], __('orders.invoice_download_link'));
    }

    public function markAsPrepared(Order $order): JsonResponse
    {
        $user = Auth::user();
        if (!$user->hasRole('supplier') || !$order->items()->where(OrderItem::SUPPLIER_USER_ID, $user->id)->exists()) {
            return error(__('auth.forbidden'), [], 403);
        }

        // --- اصلاح شرط ---
        // تامین‌کننده فقط سفارش‌هایی را می‌تواند تایید کند که پرداخت شده‌اند
        if ($order->{Order::STATUS} === Order::STATUS_PROCESSING) { // منتظر تایید
            $order->{Order::STATUS} = Order::STATUS_PREPARING; // تغییر به "جاری" (آماده‌سازی)
            $order->{Order::PREPARED_AT} = now();
            $order->save();

            // TODO: چک کردن اینکه آیا *همه* تامین‌کننده‌های این سفارش تایید کرده‌اند؟

            return success(new OrderResource($order->fresh()), __('orders.marked_as_prepared'));
        }

        // اگر وضعیت چیز دیگری بود (مثلاً 'pending' یا 'preparing')
        return error(__('orders.cannot_prepare_in_current_state'), [], 400);
    }

    public function addWaybill(Request $request, Order $order): JsonResponse
    {
        $user = Auth::user();
        if (!$user->hasRole('supplier') || !$order->items()->where(OrderItem::SUPPLIER_USER_ID, $user->id)->exists()) {
            return error(__('auth.forbidden'), [], 403);
        }
        $validated = $request->validate([Order::WAYBILL_REF => 'required|string|max:255']);
        if (in_array($order->{Order::STATUS}, [Order::STATUS_PREPARING, Order::STATUS_WEIGHBRIDGE_DONE])) {
            $order->{Order::STATUS} = Order::STATUS_WAYBILL_ISSUED; // Or STATUS_SHIPPED?
            $order->{Order::WAYBILL_REF} = $validated[Order::WAYBILL_REF];
            $order->{Order::SHIPPED_AT} = now();
            $order->save();
            return success(new OrderResource($order->fresh()), __('orders.waybill_added'));
        }
        return error(__('orders.cannot_add_waybill'), [], 400);
    }

    public function issueEspadanaCommand(Order $order): JsonResponse
    {
        $user = Auth::user();
        if (!$user->hasRole('supplier') || !$order->items()->where(OrderItem::SUPPLIER_USER_ID, $user->id)->exists()) {
            return error(__('auth.forbidden'), [], 403);
        }
        // TODO: Implement Asyadana API interaction
        $espadanaRef = 'ESPADANA_REF_' . $order->id . '_' . time(); // Placeholder
        $order->{Order::ESPADANA_ORDER_REF} = $espadanaRef;
        $order->save();
        return success(new OrderResource($order->fresh()), __('orders.espadana_command_issued'));
    }

    public function attachPaymentReceipt(Request $request, Order $order): JsonResponse
    {
        $user = Auth::user();
        if (!$user->hasRole('supplier') || !$order->items()->where(OrderItem::SUPPLIER_USER_ID, $user->id)->exists()) {
            return error(__('auth.forbidden'), [], 403);
        }
        // TODO: Handle file upload and store reference/link
        $validated = $request->validate(['receipt_ref' => 'required|string|max:255']); // Placeholder
        $order->{Order::PAYMENT_RECEIPT_REF} = $validated['receipt_ref'];
        $order->save();
        return success(new OrderResource($order->fresh()), __('orders.receipt_attached'));
    }

    /**
     * پرداخت هزینه تمدید رزرو (مرحله تمدید)
     */
    public function payHoldFee(Request $request, Order $order): JsonResponse
    {
        // 1. چک کردن دسترسی
        if ($order->{Order::PLACER_USER_ID} !== Auth::id()) {
            return error(__('auth.forbidden'), [], 403);
        }

        // 2. چک کردن وضعیت (فقط سفارش‌های رزرو شده قابل تمدید هستند)
        if ($order->{Order::STATUS} !== Order::STATUS_PENDING) {
            return error(__('orders.cannot_extend_reservation'), [], 400);
        }

        // 3. شبیه‌سازی پرداخت (دریافت ۲۰۰ میلیون و ...)
        $paymentAmount = 200000000; // مثال

        // 4. آپدیت سفارش
        $order->update([
            Order::STATUS => Order::STATUS_RESERVED_EXTENDED,
            Order::RESERVATION_EXPIRES_AT => now()->addDay(), // تمدید برای ۱ روز
            Order::PARTIAL_PAYMENT_AMOUNT => $paymentAmount,
        ]);

        return success(new OrderResource($order), __('orders.reservation_extended'));
    }

    /**
     * پرداخت کامل سفارش (مرحله ۲: ثبت اولیه پس از پرداخت)
     */
    public function payFullOrder(Request $request, Order $order): JsonResponse
    {
        // 1. چک کردن دسترسی
        if ($order->{Order::PLACER_USER_ID} !== Auth::id()) {
            return error(__('auth.forbidden'), [], 403);
        }

        // 2. چک کردن وضعیت (فقط رزروها قابل پرداخت هستند)
        if (!in_array($order->{Order::STATUS}, [Order::STATUS_PENDING, Order::STATUS_RESERVED_EXTENDED])) {
            return error(__('orders.cannot_pay_order'), [], 400);
        }

        // 3. شبیه‌سازی پرداخت کامل

        // 4. آپدیت سفارش
        $order->update([
            Order::STATUS => Order::STATUS_PROCESSING, // وضعیت: منتظر تایید تامین‌کننده
            Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
            Order::PAID_AT => now(),
            Order::RESERVATION_EXPIRES_AT => null, // رزرو دیگر معنی ندارد
        ]);

        // 5. TODO: ارسال نوتیفیکیشن به تامین‌کننده(ها) برای تایید سفارش

        return success(new OrderResource($order), __('orders.payment_successful_awaiting_confirmation'));
    }
}
