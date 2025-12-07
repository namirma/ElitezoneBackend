<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddWaybillRequest;
use App\Http\Requests\PayFullOrderRequest;
use App\Http\Requests\PayHoldFeeRequest;
use App\Http\Requests\UploadOrderReceiptRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;

class OrderController extends Controller
{
    // =========================================================================
    //  بخش ۱: لیست‌های داشبورد کاربر (My Orders)
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

    // =========================================================================
    //  بخش ۲: عملیات اصلی (CRUD & Actions)
    // =========================================================================

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $viewType = $this->determineViewType($request, $user);

        // چک دسترسی برای ویوی تامین‌کننده
        if ($viewType === 'supplier_fulfillment' && !$user->hasRole('supplier')) {
            return error(__('auth.forbidden'), [], 403);
        }

        $query = Order::query()->latest();

        $this->applyEagerLoading($query, $user, $viewType);
        $this->applyViewFilters($query, $user->id, $viewType);
        $this->applyStatusFilters($query, $request->query('status_type'));

        $orders = $query->paginate($request->input('per_page', 10));

        return success(OrderResource::collection($orders), __('global.received_successfully'));
    }

    public function show(Order $order): JsonResponse
    {
        $this->authorizeOrderView($order);

        $order->load([
            'items.productVariant',
            'items.supplier.profile',
            'address',
            'placer.profile',
            'beneficiary.profile'
        ]);

        return success(new OrderResource($order), __('global.received_successfully'));
    }

    public function cancel(Order $order): JsonResponse
    {
        $this->authorizeOrderOwner($order);

        if (!$this->isOrderCancellable($order)) {
            return error(__('orders.cannot_cancel'), [], 400);
        }

        DB::transaction(function () use ($order) {
            // ۱. بازگرداندن کالا به انبار
            $order->restoreStock();

            // ۲. بازگرداندن وجه به کیف پول (اگر پرداخت کرده باشد)
            $order->refundToWallet();

            // ۳. تغییر وضعیت
            $newPaymentStatus = ($order->payment_status === Order::PAYMENT_PAID)
                ? Order::PAYMENT_REFUNDED
                : Order::PAYMENT_FAILED;

            $order->update([
                Order::STATUS => Order::STATUS_CANCELLED,
                Order::PAYMENT_STATUS => $newPaymentStatus,
                Order::CANCEL_REASON => 'لغو توسط کاربر'
            ]);
        });

        return success(new OrderResource($order->fresh()), __('orders.cancelled_successfully'));
    }

    // =========================================================================
    //  بخش ۳: پرداخت و مالی (Payments)
    // =========================================================================

    public function payHoldFee(PayHoldFeeRequest $request, Order $order): JsonResponse
    {
        if ($order->status !== Order::STATUS_PENDING) {
            return error(__('orders.cannot_extend_reservation_status'), [], 400);
        }
        if ($order->reservation_expires_at < now()) {
            return error(__('orders.cannot_extend_reservation_expired'), [], 400);
        }

        // TODO: اتصال به درگاه پرداخت
        $order->update([
            Order::STATUS => Order::STATUS_RESERVED_EXTENDED,
            Order::RESERVATION_EXPIRES_AT => now()->addDay(),
            Order::PARTIAL_PAYMENT_AMOUNT => $request->validated('amount'),
        ]);

        return success(new OrderResource($order->fresh()), __('orders.reservation_extended'));
    }

    public function payFullOrder(PayFullOrderRequest $request, Order $order): JsonResponse
    {
        $this->authorizeOrderOwner($order);

        if (!in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_RESERVED_EXTENDED])) {
            return error(__('orders.cannot_pay_order'), [], 400);
        }

        $gateway = $request->validated('gateway');

        if ($gateway === 'wallet') {
            return $this->processWalletPayment($order);
        }

        // پرداخت بانکی (لینک)
        $paymentUrl = "https://payment-gateway.com/pay?order={$order->id}&provider={$gateway}";

        return success(['redirect_url' => $paymentUrl], __('orders.payment_redirect'));
    }

    public function uploadCustomerReceipt(UploadOrderReceiptRequest $request, Order $order): JsonResponse
    {
        $this->authorizeOrderOwner($order);

        if ($order->payment_status === Order::PAYMENT_PAID) {
            return error(__('orders.already_paid'), [], 400);
        }

        $path = $request->file('receipt')->store('order_receipts', 'public');
        $order->update([Order::PAYMENT_RECEIPT_REF => $path]);

        return success(new OrderResource($order), __('orders.receipt_uploaded_success'));
    }

    public function downloadInvoice(Order $order, string $type): JsonResponse
    {
        $this->authorizeOrderView($order);

        $ref = match ($type) {
            'initial' => $order->initial_invoice_ref,
            'final'   => $order->final_invoice_ref,
            'tax'     => $order->tax_invoice_ref,
            default   => null,
        };

        if (!$ref) {
            return error(__('orders.invoice_not_found'), [], 404);
        }

        return success(['download_link' => url("/invoices/placeholder/{$ref}")], __('orders.invoice_download_link'));
    }

    // =========================================================================
    //  بخش ۴: پنل تامین‌کننده (Supplier Actions)
    // =========================================================================

    public function markAsPrepared(Order $order): JsonResponse
    {
        $this->authorizeSupplier($order);

        if ($order->status === Order::STATUS_PROCESSING) {
            $order->update([
                Order::STATUS => Order::STATUS_PREPARING,
                Order::PREPARED_AT => now()
            ]);
            return success(new OrderResource($order), __('orders.marked_as_prepared'));
        }
        return error(__('orders.cannot_prepare_in_current_state'), [], 400);
    }

    public function addWaybill(AddWaybillRequest $request, Order $order): JsonResponse
    {
        $this->authorizeSupplier($order);

        if (in_array($order->status, [Order::STATUS_PREPARING, Order::STATUS_WEIGHBRIDGE_DONE])) {
            $order->update([
                Order::STATUS => Order::STATUS_WAYBILL_ISSUED,
                Order::WAYBILL_REF => $request->validated(Order::WAYBILL_REF),
                Order::SHIPPED_AT => now()
            ]);
            return success(new OrderResource($order), __('orders.waybill_added'));
        }
        return error(__('orders.cannot_add_waybill'), [], 400);
    }

    public function issueEspadanaCommand(Order $order): JsonResponse
    {
        $this->authorizeSupplier($order);
        // TODO: Call External API
        $order->update([Order::ESPADANA_ORDER_REF => 'ESPADANA_' . $order->id . '_' . time()]);
        return success(new OrderResource($order), __('orders.espadana_command_issued'));
    }

    public function attachPaymentReceipt(UploadOrderReceiptRequest $request, Order $order): JsonResponse
    {
        $this->authorizeSupplier($order); // برای تامین‌کننده

        $path = $request->file('receipt')->store('supplier_receipts', 'public');
        // اینجا شاید فیلد متفاوتی در دیتابیس باشد یا همان payment_receipt_ref
        // فعلاً فرض می‌کنیم همان است
        $order->update([Order::PAYMENT_RECEIPT_REF => $path]);

        return success(new OrderResource($order), __('orders.receipt_attached'));
    }

    // =========================================================================
    //  بخش ۵: متدهای خصوصی و کمکی (Helpers)
    // =========================================================================

    private function getOrdersByStatus(Request $request, string $type, string $tabStatus): JsonResponse
    {
        $userId = Auth::id();
        $query = Order::query()->with(['items.productVariant', 'items.supplier.profile', 'beneficiary.profile', 'placer.profile']);

        if ($type === 'myself') {
            $query->where(Order::PLACER_USER_ID, $userId)
                  ->where(fn($q) => $q->whereNull(Order::BENEFICIARY_USER_ID)->orWhere(Order::BENEFICIARY_USER_ID, $userId));
        } elseif ($type === 'others') {
            $query->where(Order::PLACER_USER_ID, $userId)
                  ->whereNotNull(Order::BENEFICIARY_USER_ID)
                  ->where(Order::BENEFICIARY_USER_ID, '!=', $userId);
        }

        match ($tabStatus) {
            'current' => $query->whereIn(Order::STATUS, [
                Order::STATUS_PENDING, Order::STATUS_RESERVED_EXTENDED, Order::STATUS_PROCESSING,
                Order::STATUS_PREPARING, Order::STATUS_WEIGHBRIDGE_DONE, Order::STATUS_WAYBILL_ISSUED,
                Order::STATUS_SHIPPED
            ]),
            'delivered' => $query->where(Order::STATUS, Order::STATUS_DELIVERED),
            'cancelled' => $query->whereIn(Order::STATUS, [Order::STATUS_CANCELLED, Order::STATUS_FAILED]),
            default => null
        };

        $orders = $query->latest()->paginate($request->input('per_page', 10));
        return success(OrderResource::collection($orders), __('global.received_successfully'));
    }

    private function processWalletPayment(Order $order): JsonResponse
    {
        $user = Auth::user();
        $wallet = $user->wallet()->lockForUpdate()->firstOrCreate();

        if ($wallet->balance < $order->final_amount) {
            return error(__('orders.wallet_balance_low'), [], 400);
        }

        DB::transaction(function () use ($wallet, $order) {
            $wallet->decrement('balance', $order->final_amount);

            $wallet->transactions()->create([
                'amount' => $order->final_amount,
                'type' => 'withdraw',
                'status' => 'confirmed',
                'description' => "پرداخت سفارش #{$order->order_number}",
                'order_id' => $order->id
            ]);

            $order->update([
                Order::STATUS => Order::STATUS_PROCESSING,
                Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
                Order::PAID_AT => now(),
                Order::RESERVATION_EXPIRES_AT => null,
            ]);
        });

        return success(new OrderResource($order->fresh()), __('orders.payment_success_wallet'));
    }

    // --- Authorization Helpers ---

    private function authorizeOrderOwner(Order $order): void
    {
        if ($order->placer_user_id !== Auth::id()) {
            abort(error(__('auth.forbidden'), [], 403));
        }
    }

    private function authorizeOrderView(Order $order): void
    {
        $userId = Auth::id();
        $isOwner = $order->placer_user_id === $userId || $order->beneficiary_user_id === $userId;
        $isSupplier = Auth::user()->hasRole('supplier') && $order->items()->where(OrderItem::SUPPLIER_USER_ID, $userId)->exists();

        if (!$isOwner && !$isSupplier) {
            abort(error(__('auth.forbidden'), [], 403));
        }
    }

    private function authorizeSupplier(Order $order): void
    {
        $user = Auth::user();
        if (!$user->hasRole('supplier') || !$order->items()->where(OrderItem::SUPPLIER_USER_ID, $user->id)->exists()) {
            abort(error(__('auth.forbidden'), [], 403));
        }
    }

    private function isOrderCancellable(Order $order): bool
    {
        return in_array($order->status, [
            Order::STATUS_PENDING, Order::STATUS_RESERVED_EXTENDED,
            Order::STATUS_PROCESSING, Order::STATUS_PREPARING,
            Order::STATUS_WEIGHBRIDGE_DONE, Order::STATUS_WAYBILL_ISSUED
        ]);
    }

    private function determineViewType(Request $request, User $user): string
    {
        $type = $request->query('view_type');
        if ($user->hasRole('supplier') && !$type) {
            return 'supplier_fulfillment';
        }
        return $type ?? 'my_direct';
    }

    private function applyEagerLoading(Builder $query, User $user, string $viewType): void
    {
        $query->with([
            'items' => function ($q) use ($viewType, $user) {
                if ($viewType === 'supplier_fulfillment') {
                    $q->where(OrderItem::SUPPLIER_USER_ID, $user->id);
                }
                $q->with(['productVariant', 'supplier.profile']);
            },
            'address', 'placer.profile', 'beneficiary.profile'
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
                Order::STATUS_PENDING, Order::STATUS_RESERVED_EXTENDED, Order::STATUS_PROCESSING,
                Order::STATUS_PREPARING, Order::STATUS_WEIGHBRIDGE_DONE, Order::STATUS_WAYBILL_ISSUED,
                Order::STATUS_SHIPPED
            ]);
        }
    }
}
