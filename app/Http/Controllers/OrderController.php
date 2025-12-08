<?php

namespace App\Http\Controllers;

use App\Actions\Order\CancelOrderAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddWaybillRequest;
use App\Http\Requests\PayFullOrderRequest;
use App\Http\Requests\PayHoldFeeRequest;
use App\Http\Requests\UploadOrderReceiptRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Mccarlosen\LaravelMpdf\Facades\LaravelMpdf as PDF;

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

    public function cancel(Order $order, CancelOrderAction $cancelAction): JsonResponse
    {
        $userId = Auth::id();

        // ۱. چک دسترسی
        if ($order->{Order::PLACER_USER_ID} !== $userId && $order->{Order::BENEFICIARY_USER_ID} !== $userId) {
            return error(__('auth.forbidden'), [], 403);
        }

        // ۲. چک وضعیت (آیا قابل لغو است؟)
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

        // ۳. اجرای اکشن (فراخوانی منطق مرکزی)
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

    public function downloadInvoice(Order $order, string $type)
    {

        $this->authorizeOrderView($order);
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

        // 4. اطلاعات خریدار
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
            'mode'         => 'utf-8',
            'format'       => 'A4',
            'orientation'  => 'P',
            'margin_left'  => 5,
            'margin_right' => 5,
            'margin_top'   => 5,
            'margin_bottom' => 5,
            'default_font' => 'yekan'
        ]);

        return $pdf->download("invoice-{$order->order_number}.pdf");
    }

    private function numberToWords($number)
    {
        $ones = array('', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه', 'ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده');
        $tens = array('', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود');
        $thousands = array('', 'هزار', 'میلیون', 'میلیارد', 'تریلیون');

        if ($number == 0) return 'صفر';

        $number = (string) $number;
        $number = str_pad($number, ceil(strlen($number) / 3) * 3, '0', STR_PAD_LEFT);
        $parts = str_split($number, 3);
        $result = [];

        foreach ($parts as $key => $part) {
            $part = (int) $part;
            if ($part == 0) continue;

            $hundredsDigit = floor($part / 100);
            $tensDigit = floor(($part % 100) / 10);
            $onesDigit = $part % 10;
            $partText = [];

            if ($hundredsDigit > 0) {
                $hundredsNames = ['', 'صد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
                $partText[] = $hundredsNames[$hundredsDigit];
            }

            if ($tensDigit > 1) {
                $partText[] = $tens[$tensDigit];
                if ($onesDigit > 0) $partText[] = $ones[$onesDigit];
            } elseif ($tensDigit == 1) {
                $partText[] = $ones[10 + $onesDigit];
            } elseif ($onesDigit > 0) {
                $partText[] = $ones[$onesDigit];
            }

            $level = count($parts) - $key - 1;
            $result[] = implode(' و ', $partText) . ($level > 0 ? ' ' . $thousands[$level] : '');
        }

        return implode(' و ', $result);
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

    private function processWalletPayment(Order $order): JsonResponse
    {
        try {
            // شروع تراکنش اتمیک (Atomic Transaction)
            return DB::transaction(function () use ($order) {
                $user = Auth::user();

                // ۱. قفل کردن کیف پول برای جلوگیری از برداشت همزمان
                $wallet = $user->wallet()->lockForUpdate()->firstOrCreate();

                // بررسی موجودی کیف پول
                if ($wallet->balance < $order->final_amount) {
                    throw new \Exception(__('orders.wallet_balance_low'));
                }
                (new InventoryService())->deductStockForOrder($order);

                // ۳. کسر مبلغ از کیف پول
                $wallet->decrement('balance', $order->final_amount);

                // ۴. ثبت تراکنش مالی در تاریخچه کیف پول
                $wallet->transactions()->create([
                    'amount' => $order->final_amount,
                    'type' => 'withdraw', // بهتر است این هم به صورت ثابت (Const) تعریف شود
                    'status' => 'confirmed',
                    'description' => "پرداخت سفارش #{$order->order_number}",
                    'order_id' => $order->id
                ]);

                // ۵. به‌روزرسانی وضعیت سفارش با استفاده از ثابت‌های مدل
                $order->update([
                    Order::STATUS => Order::STATUS_PROCESSING,
                    Order::PAYMENT_STATUS => Order::PAYMENT_PAID,
                    Order::PAID_AT => now(),
                    Order::RESERVATION_EXPIRES_AT => null,
                ]);

                return success(new OrderResource($order->fresh()), __('orders.payment_success_wallet'));
            });
        } catch (\Exception $e) {
            return error($e->getMessage(), [], 400);
        }
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
            Order::STATUS_PENDING,
            Order::STATUS_RESERVED_EXTENDED,
            Order::STATUS_PROCESSING,
            Order::STATUS_PREPARING,
            Order::STATUS_WEIGHBRIDGE_DONE,
            Order::STATUS_WAYBILL_ISSUED
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
}
