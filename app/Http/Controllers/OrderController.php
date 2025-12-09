<?php

namespace App\Http\Controllers;

use App\Actions\Order\CancelOrderAction;
use App\Actions\Order\PayOrderAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddWaybillRequest;
use App\Http\Requests\PayFullOrderRequest;
use App\Http\Requests\PayHoldFeeRequest;
use App\Http\Requests\UploadOrderReceiptRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Repositories\Contracts\OrderRepositoryInterface;
use App\Traits\ConvertNumberToWord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use Mccarlosen\LaravelMpdf\Facades\LaravelMpdf as PDF;

class OrderController extends Controller
{
    use ConvertNumberToWord;

    public function __construct(
        protected OrderRepositoryInterface $orderRepository
    ) {}

    // =========================================================================
    //  بخش ۱: لیست‌های داشبورد کاربر (User Dashboard Lists)
    // =========================================================================

    public function listMyCurrentOrders(Request $request): JsonResponse
    {
        $orders = $this->orderRepository->getUserOrders(Auth::id(), 'myself', 'current', $request->input('per_page', 10));
        return success(OrderResource::collection($orders));
    }

    public function listMyDeliveredOrders(Request $request): JsonResponse
    {
        $orders = $this->orderRepository->getUserOrders(Auth::id(), 'myself', 'delivered', $request->input('per_page', 10));
        return success(OrderResource::collection($orders));
    }

    public function listMyCancelledOrders(Request $request): JsonResponse
    {
        $orders = $this->orderRepository->getUserOrders(Auth::id(), 'myself', 'cancelled', $request->input('per_page', 10));
        return success(OrderResource::collection($orders));
    }

    public function listOthersCurrentOrders(Request $request): JsonResponse
    {
        $orders = $this->orderRepository->getUserOrders(Auth::id(), 'others', 'current', $request->input('per_page', 10));
        return success(OrderResource::collection($orders));
    }

    public function listOthersDeliveredOrders(Request $request): JsonResponse
    {
        $orders = $this->orderRepository->getUserOrders(Auth::id(), 'others', 'delivered', $request->input('per_page', 10));
        return success(OrderResource::collection($orders));
    }

    public function listOthersCancelledOrders(Request $request): JsonResponse
    {
        $orders = $this->orderRepository->getUserOrders(Auth::id(), 'others', 'cancelled', $request->input('per_page', 10));
        return success(OrderResource::collection($orders));
    }

    // =========================================================================
    //  بخش ۲: عملیات اصلی (Core Operations)
    // =========================================================================

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $viewType = $request->query('view_type', 'my_direct');

        if ($viewType === 'supplier_fulfillment' && !$user->hasRole('supplier')) {
            return error(__('auth.forbidden'), [], 403);
        }
        $query = Order::query()->latest();
        $this->applyIndexFilters($query, $user, $viewType, $request->query('status_type'));

        $orders = $query->paginate($request->input('per_page', 10));

        return success(OrderResource::collection($orders), __('global.received_successfully'));
    }

    public function show(Order $order): JsonResponse
    {
        // استفاده از Policy به جای authorizeOrderView
        $this->authorize('view', $order);

        $order->load(['items.productVariant', 'items.supplier.profile', 'address', 'placer.profile', 'beneficiary.profile']);
        return success(new OrderResource($order), __('global.received_successfully'));
    }

    public function cancel(Order $order, CancelOrderAction $cancelAction): JsonResponse
    {
        $this->authorize('cancel', $order);

        if (!in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_RESERVED_EXTENDED, Order::STATUS_PROCESSING, Order::STATUS_PREPARING, Order::STATUS_WEIGHBRIDGE_DONE, Order::STATUS_WAYBILL_ISSUED])) {
            return error(__('orders.cannot_cancel'), [], 400);
        }

        $cancelAction->execute($order, 'لغو توسط کاربر');
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

        $order->update([
            Order::STATUS => Order::STATUS_RESERVED_EXTENDED,
            Order::RESERVATION_EXPIRES_AT => now()->addDay(),
            Order::PARTIAL_PAYMENT_AMOUNT => $request->validated('amount'),
        ]);

        return success(new OrderResource($order->fresh()), __('orders.reservation_extended'));
    }

    public function payFullOrder(PayFullOrderRequest $request, Order $order, PayOrderAction $payOrderAction): JsonResponse
    {
        // استفاده از Policy برای حق پرداخت
        $this->authorize('pay', $order);

        if (!in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_RESERVED_EXTENDED])) {
            return error(__('orders.cannot_pay_order'), [], 400);
        }

        $gateway = $request->validated('gateway');

        if ($gateway === 'wallet') {
            try {
                $payOrderAction->execute(Auth::user(), $order, 'wallet');
                return success(new OrderResource($order->fresh()), __('orders.payment_success_wallet'));
            } catch (\Exception $e) {
                return error($e->getMessage(), [], 400);
            }
        }

        $paymentUrl = "https://payment-gateway.com/pay?order={$order->id}&provider={$gateway}";
        return success(['redirect_url' => $paymentUrl], __('orders.payment_redirect'));
    }

    public function uploadCustomerReceipt(UploadOrderReceiptRequest $request, Order $order): JsonResponse
    {
        $this->authorize('pay', $order);

        if ($order->payment_status === Order::PAYMENT_PAID) {
            return error(__('orders.already_paid'), [], 400);
        }

        $path = $request->file('receipt')->store('order_receipts', 'public');
        $order->update([Order::PAYMENT_RECEIPT_REF => $path]);

        return success(new OrderResource($order), __('orders.receipt_uploaded_success'));
    }

    // =========================================================================
    //  بخش ۴: فاکتور (Invoicing)
    // =========================================================================

    public function downloadInvoice(Order $order, string $type)
    {
        $this->authorize('view', $order);

        $order->load(['items.productVariant', 'placer.profile', 'address.city', 'address.province']);

        $seller = [
            'name'          => 'شرکت نرم‌افزاری سازه اسپادانا',
            'economic_code' => '411347688653',
            'reg_id'        => '31106',
            'national_id'   => '10260516722',
            'address'       => 'اصفهان، پارک علم و فناوری، خیابان سلمان فارسی، ساختمان شهرک علمی تحقیقاتی، طبقه اول، واحد 114',
            'phone'         => '03134005000',
            'postal_code'   => '8158184500',
        ];

        $buyerProfile = $order->placer->profile;
        $buyer = [
            'name'          => ($buyerProfile->first_name ?? '') . ' ' . ($buyerProfile->last_name ?? ''),
            'economic_code' => $buyerProfile->economic_code ?? '-',
            'national_id'   => $order->placer->national_code ?? '-',
            'reg_id'        => null,
            'postal_code'   => $order->address->postal_code ?? '-',
            'phone'         => $order->placer->mobile,
            'address'       => $order->address->postal_address ?? $order->address->address ?? '-',
        ];

        $amountInWords = $this->numberToWords($order->final_amount);

        $data = [
            'order'         => $order,
            'seller'        => $seller,
            'buyer'         => $buyer,
            'type'          => $type,
            'date'          => verta($order->created_at)->format('Y/m/d'),
            'validity_date' => verta($order->created_at)->addDay()->format('Y/m/d'),
            'amount_words'  => $amountInWords,
        ];

        $pdf = PDF::loadView('pdf.invoice', $data, [], [
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'orientation'   => 'P',
            'margin_left'   => 5,
            'margin_right'  => 5,
            'margin_top'    => 5,
            'margin_bottom' => 5,
            'default_font'  => 'yekan'
        ]);

        return $pdf->download("invoice-{$order->order_number}.pdf");
    }

    // =========================================================================
    //  بخش ۵: پنل تامین‌کننده (Supplier Actions)
    // =========================================================================

    public function markAsPrepared(Order $order): JsonResponse
    {
        $this->authorize('manageAsSupplier', $order);

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
        $this->authorize('manageAsSupplier', $order);

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
        $this->authorize('manageAsSupplier', $order);

        $order->update([Order::ESPADANA_ORDER_REF => 'ESPADANA_' . $order->id . '_' . time()]);
        return success(new OrderResource($order), __('orders.espadana_command_issued'));
    }

    public function attachPaymentReceipt(UploadOrderReceiptRequest $request, Order $order): JsonResponse
    {
        $this->authorize('manageAsSupplier', $order);

        $path = $request->file('receipt')->store('supplier_receipts', 'public');
        $order->update([Order::PAYMENT_RECEIPT_REF => $path]);

        return success(new OrderResource($order), __('orders.receipt_attached'));
    }

    // =========================================================================
    //  بخش ۶: متدهای کمکی (Helpers)
    // =========================================================================

    /**
     * اعمال فیلترهای لیست اصلی سفارشات
     */
    private function applyIndexFilters(Builder $query, User $user, string $viewType, ?string $statusType): void
    {
        $userId = $user->id;

        $query->with([
            'items' => function ($q) use ($viewType, $userId) {
                if ($viewType === 'supplier_fulfillment') {
                    $q->where('supplier_user_id', $userId);
                }
                $q->with(['productVariant', 'supplier.profile']);
            },
            'address',
            'placer.profile',
            'beneficiary.profile'
        ]);

        match ($viewType) {
            'supplier_fulfillment' => $query->whereHas('items', fn($q) => $q->where('supplier_user_id', $userId)),
            'pending_surveys' => $query->where(fn($q) => $q->where('placer_user_id', $userId)->orWhere('beneficiary_user_id', $userId))
                ->where('status', Order::STATUS_DELIVERED)
                ->where('survey_status', '!=', Order::SURVEY_COMPLETED),
            'my_direct' => $query->where('placer_user_id', $userId)
                ->where(fn($q) => $q->whereNull('beneficiary_user_id')->orWhere('beneficiary_user_id', $userId)),
            'for_others' => $query->where('placer_user_id', $userId)
                ->whereNotNull('beneficiary_user_id')
                ->where('beneficiary_user_id', '!=', $userId),
            'employer_associated' => $query->where('beneficiary_user_id', $userId)
                ->where('placer_user_id', '!=', $userId),
            default => $query->whereRaw('1 = 0'),
        };

        if ($statusType === 'history') {
            $query->whereIn('status', [Order::STATUS_DELIVERED, Order::STATUS_CANCELLED, Order::STATUS_FAILED]);
        } else {
            $query->whereIn('status', [
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
}
