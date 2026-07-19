<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Financial\RecordSupplierMoadianInvoiceAction;
use App\Actions\Order\CancelOrderAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\AddWaybillRequest;
use App\Http\Requests\Order\SubmitMoadianInvoiceRequest;
use App\Http\Requests\Order\UploadOrderReceiptRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Order;
use App\Models\SupplierProfile;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SupplierOrderController extends Controller
{
    /**
     * لیست خودسرویس سفارش‌های مربوط به سوپلایر جاری (صف کاری).
     *
     * Query params: status (nullable), per_page (nullable, max 100)
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        $request->validate([
            'status' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = Order::query()
            ->whereHas('items', fn (Builder $q) => $q->where('supplier_user_id', $user->id))
            ->with(Order::resourceRelations())
            ->when(
                $request->filled('status'),
                fn (Builder $q) => $q->where(Order::STATUS, $request->status)
            )
            ->latest()
            ->paginate($this->perPage(10));

        return success(OrderResource::collection($orders), __('global.received_successfully'));
    }

    /**
     * داشبورد خودسرویس سوپلایر — آمار سفارش به تفکیک وضعیت، بدهی معلق،
     * فاکتورهای مودیانِ ثبت‌نشده، فلگ‌های فرمول تسویه، و خلاصه کیف پول.
     *
     * معادل خودسرویسِ AdminSupplierController::show()، بدون بخش‌های ادمین‌محور
     * (پروفایل کامل/نقش‌ها) — کاربر آن‌ها را از GET /profile می‌گیرد.
     */
    public function dashboard(): JsonResponse
    {
        $user = Auth::user();
        $user->load(['supplier', 'wallet']);

        $myItems = fn (Builder $q) => $q->where('supplier_user_id', $user->id);

        $orderStats = Order::query()
            ->whereHas('items', $myItems)
            ->selectRaw('status, COUNT(*) as count, SUM(final_amount) as total_amount')
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status,
                'count' => (int) $row->count,
                'total_amount' => (float) $row->total_amount,
            ]);

        $pendingMoadianCount = Order::query()
            ->whereHas('items', $myItems)
            ->whereNotNull(Order::WEIGHING_COMPLETED_AT)
            ->whereNull(Order::SUPPLIER_MOADIAN_REF)
            ->count();

        $pendingDebt = Order::query()
            ->whereHas('items', $myItems)
            ->where(Order::SUPPLIER_DEBT_PENDING, true)
            ->selectRaw('COUNT(*) as count, SUM(supplier_debt_amount) as total_amount')
            ->first();

        return success([
            'supplier_status' => $user->supplier?->{SupplierProfile::STATUS},
            'formula_flags' => $user->supplier ? [
                'trans_commission' => (bool) $user->supplier->{SupplierProfile::TRANS_COMMISSION},
                'trans_vat' => (bool) $user->supplier->{SupplierProfile::TRANS_VAT},
            ] : null,
            'order_stats' => $orderStats,
            'pending_moadian_count' => $pendingMoadianCount,
            'pending_debt' => [
                'count' => (int) ($pendingDebt->count ?? 0),
                'total_amount' => (float) ($pendingDebt->total_amount ?? 0),
            ],
            'wallet' => $user->wallet ? [
                'balance' => (float) $user->wallet->balance,
                'balance_hold' => (float) $user->wallet->balance_hold,
            ] : null,
        ], __('global.received_successfully'));
    }

    /**
     * سفارشاتی که بدهی معلق سوپلایر روی آن‌هاست (drill-down برای dashboard.pending_debt).
     *
     * معادل خودسرویسِ AdminFinancialController::pendingDebts()، محدود به سفارشاتی
     * که آیتمی با supplier_user_id = خودم دارند.
     */
    public function pendingDebts(): JsonResponse
    {
        $user = Auth::user();

        $orders = Order::query()
            ->where(Order::SUPPLIER_DEBT_PENDING, true)
            ->whereHas('items', fn (Builder $q) => $q->where('supplier_user_id', $user->id))
            ->with(Order::resourceRelations())
            ->latest()
            ->paginate($this->perPage(20));

        return success(OrderResource::collection($orders), __('admin.financial.pending_debts_received'));
    }

    /**
     * سفارشاتی که باسکول شده‌اند ولی فاکتور مودیانِ *خودِ سوپلایر* (Doc O) هنوز ثبت
     * نشده (drill-down برای dashboard.pending_moadian_count).
     *
     * برخلاف نسخه‌ی ادمین (AdminFinancialController::pendingMoadianInvoices) که هر
     * سه سند Q/O/P را با هم می‌بیند، اینجا فقط Doc O مهم است — سوپلایر نه به سند
     * الیت‌یار دسترسی دارد نه به سند سیستم.
     */
    public function pendingMoadian(): JsonResponse
    {
        $user = Auth::user();

        $orders = Order::query()
            ->whereHas('items', fn (Builder $q) => $q->where('supplier_user_id', $user->id))
            ->whereNotNull(Order::WEIGHING_COMPLETED_AT)
            ->whereNull(Order::SUPPLIER_MOADIAN_REF)
            ->whereIn(Order::STATUS, [
                Order::STATUS_WEIGHBRIDGE_DONE,
                Order::STATUS_WAYBILL_ISSUED,
                Order::STATUS_DELIVERED,
                Order::STATUS_COMPLETED,
            ])
            ->with(Order::resourceRelations())
            ->latest()
            ->paginate($this->perPage(20));

        return success(OrderResource::collection($orders), __('admin.financial.pending_moadian_received'));
    }

    public function markAsPrepared(Order $order): JsonResponse
    {
        $this->authorize('manageAsSupplier', $order);

        if ($order->status === Order::STATUS_PROCESSING) {
            $order->update([
                Order::STATUS => Order::STATUS_PREPARING,
                Order::PREPARED_AT => now(),
            ]);

            $order->loadMissing(Order::resourceRelations());

            return success(new OrderResource($order), __('orders.marked_as_prepared'));
        }

        return error(__('orders.cannot_prepare_in_current_state'), [], 400);
    }

    /**
     * رد سفارش توسط سوپلایر — نیمه‌ی گم‌شده‌ی «تأیید/رد سفارش» (تأیید = markAsPrepared).
     *
     * فقط روی سفارشِ «در حال پردازش» (پیش از آماده‌سازی/حمل) مجاز است. خریدار از طریق
     * CancelOrderAction به‌طور کامل refund می‌شود (همان مسیرِ امنِ لغو که ادمین استفاده می‌کند).
     */
    public function rejectOrder(Request $request, Order $order, CancelOrderAction $cancel): JsonResponse
    {
        $this->authorize('manageAsSupplier', $order);

        if ($order->status !== Order::STATUS_PROCESSING) {
            return error(__('orders.cannot_reject_in_current_state'), [], 400);
        }

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $cancel->execute($order, $data['reason'], true, true);

        return success(null, __('orders.supplier_rejected'));
    }

    public function addWaybill(AddWaybillRequest $request, Order $order): JsonResponse
    {
        $this->authorize('manageAsSupplier', $order);

        if (in_array($order->status, [Order::STATUS_PREPARING, Order::STATUS_WEIGHBRIDGE_DONE])) {
            $order->update([
                Order::STATUS => Order::STATUS_WAYBILL_ISSUED,
                Order::WAYBILL_REF => $request->validated('waybill_ref'),
                Order::SHIPPED_AT => now(),
            ]);

            $order->loadMissing(Order::resourceRelations());

            return success(new OrderResource($order), __('orders.waybill_added'));
        }

        return error(__('orders.cannot_add_waybill'), [], 400);
    }

    public function issueEspadanaCommand(Order $order): JsonResponse
    {
        $this->authorize('manageAsSupplier', $order);

        $order->update([Order::ESPADANA_ORDER_REF => 'ESPADANA_'.$order->id.'_'.time()]);

        $order->loadMissing(Order::resourceRelations());

        return success(new OrderResource($order), __('orders.espadana_command_issued'));
    }

    public function attachPaymentReceipt(UploadOrderReceiptRequest $request, Order $order): JsonResponse
    {
        $this->authorize('manageAsSupplier', $order);

        // Private disk — supplier payment receipts must not be reachable via a public URL.
        $path = $request->file('receipt')->store('supplier_receipts', 'financial');
        $order->update([Order::PAYMENT_RECEIPT_REF => $path]);

        $order->loadMissing(Order::resourceRelations());

        return success(new OrderResource($order), __('orders.receipt_attached'));
    }

    /**
     * ثبت فاکتور مودیان سوپلایر — Doc O
     *
     * پس از ثبت، هولد مالیات سوپلایر در WVAT آزاد می‌شود.
     */
    public function submitSupplierMoadianInvoice(
        SubmitMoadianInvoiceRequest $request,
        Order $order,
        RecordSupplierMoadianInvoiceAction $action,
    ): JsonResponse {
        $this->authorize('manageAsSupplier', $order);

        if ($order->{Order::SUPPLIER_MOADIAN_REF} !== null) {
            return error(__('orders.supplier_moadian_already_done'), [], 409);
        }

        try {
            $action->execute($order, $request->validated('moadian_ref'));
        } catch (\RuntimeException $e) {
            return error($e->getMessage(), [], 422);
        } catch (Exception $e) {
            return error(__('orders.unknown_processing_error'), [], 500);
        }

        return success([], __('orders.supplier_moadian_submitted'));
    }
}
