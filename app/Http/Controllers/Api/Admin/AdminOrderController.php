<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Financial\RecordSystemMoadianInvoiceAction;
use App\Actions\Order\CancelOrderAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrderFilterRequest;
use App\Http\Requests\Order\SubmitMoadianInvoiceRequest;
use App\Http\Resources\Order\OrderResource;
use App\Models\Order;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminOrderController extends Controller
{
    public function history(Order $order): JsonResponse
    {

        $activities = $order->activities()
            ->with('causer.profile')
            ->latest()
            ->get();

        $history = $activities->map(function ($activity) {
            return [
                'event' => $activity->description,
                'causer_name' => $activity->causer?->profile?->name ?? 'سیستم',
                'created_at' => verta($activity->created_at)->format('Y/m/d H:i'),
                'changes' => $activity->properties['attributes'] ?? [],
                'old' => $activity->properties['old'] ?? [],
            ];
        });

        return success($history, __('admin.order_history_received'));
    }

    public function index(OrderFilterRequest $request): JsonResponse
    {

        $orders = Order::query()
            ->with(Order::resourceRelations())
            ->when($request->filled('order_number'), fn (Builder $q) => $q->where(fn (Builder $sub) => $sub->where(Order::ORDER_NUMBER, 'like', "%{$request->order_number}%")
                ->orWhere(Order::ID, $request->order_number)
            ))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('created_at', '>=', $request->date_from)
            )
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('created_at', '<=', $request->date_to)
            )
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->status)
            )
            ->latest()
            ->paginate($this->perPage());

        return success(OrderResource::collection($orders));
    }

    public function show(Order $order): JsonResponse
    {
        $order->load(Order::resourceRelations());

        return success(new OrderResource($order));
    }

    public function changeStatus(Request $request, Order $order, CancelOrderAction $cancelAction): JsonResponse
    {
        $allowedStatuses = [
            Order::STATUS_PENDING,
            Order::STATUS_PROCESSING,
            Order::STATUS_SHIPPED,
            Order::STATUS_DELIVERED,
            Order::STATUS_CANCELLED,
        ];
        $request->validate(['status' => 'required|string|in:'.implode(',', $allowedStatuses)]);

        $newStatus = $request->input('status');

        // Cancelling MUST go through CancelOrderAction so the full reversal runs:
        // wallet refund (buyer + supplier legs), stock restoration, OrderCancelled
        // event and the refund/cancel documents. A bare status update here would
        // corrupt the ledger and inventory. isSystemCron=true lets the back-office
        // operator also unwind reserved orders.
        if ($newStatus === Order::STATUS_CANCELLED) {
            try {
                $cancelAction->execute($order, __('admin.orders.cancelled_by_admin'), true, true);
            } catch (Exception $e) {
                Log::error('Admin order cancel failed: '.$e->getMessage(), ['order_id' => $order->id]);

                return error(__('orders.cannot_cancel'), [], 422);
            }

            return success(null, __('orders.cancelled_successfully'));
        }

        // ماشین وضعیت — فقط ترنزیشن‌های رو به جلوی مجاز؛ پرش (مثلاً pending → delivered)
        // مراحل پرداخت/باسکول را دور می‌زند و وضعیت‌های نهایی قابل برگشت نیستند.
        $allowedTransitions = [
            Order::STATUS_PENDING => [Order::STATUS_PROCESSING],
            Order::STATUS_PROCESSING => [Order::STATUS_SHIPPED],
            Order::STATUS_PREPARING => [Order::STATUS_SHIPPED],
            Order::STATUS_WEIGHBRIDGE_DONE => [Order::STATUS_SHIPPED],
            Order::STATUS_WAYBILL_ISSUED => [Order::STATUS_SHIPPED],
            Order::STATUS_SHIPPED => [Order::STATUS_DELIVERED],
        ];

        $currentStatus = $order->{Order::STATUS};

        if (! in_array($newStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
            return error(
                __('admin.orders.invalid_status_transition'),
                ['current' => $currentStatus, 'requested' => $newStatus],
                422
            );
        }

        // PENDING یعنی هنوز پرداخت نشده. PROCESSING آغاز پایپلاین پس-از-پرداخت است
        // (سوپلایر سفارش را می‌بیند، آماده‌سازی/باسکول ممکن می‌شود) — بدون پرداخت
        // واقعی هیچ لگ مالی ثبت نشده و مسیر پرداخت کاربر هم بعداً بسته می‌شود.
        // این ترنزیشن فقط برای سفارشِ پرداخت‌شده مجاز است.
        if (
            $currentStatus === Order::STATUS_PENDING
            && $newStatus === Order::STATUS_PROCESSING
            && $order->{Order::PAYMENT_STATUS} !== Order::PAYMENT_PAID
        ) {
            return error(
                __('admin.orders.cannot_process_unpaid'),
                ['payment_status' => $order->{Order::PAYMENT_STATUS}],
                422
            );
        }

        $order->update([Order::STATUS => $newStatus]);

        return success(null, __('admin.orders.status_updated'));
    }

    /**
     * ثبت فاکتور مودیان سیستم (سازه) — Doc P
     *
     * پس از ثبت، هولد مالیات پلتفرم (sazeVat) در WVAT آزاد می‌شود
     * و Doc P تولید می‌گردد. فقط توسط ادمین قابل فراخوانی است.
     */
    public function submitSystemMoadianInvoice(
        SubmitMoadianInvoiceRequest $request,
        Order $order,
        RecordSystemMoadianInvoiceAction $action,
    ): JsonResponse {
        if ($order->{Order::SYSTEM_MOADIAN_REF} !== null) {
            return error(__('orders.system_moadian_already_done'), [], 409);
        }

        try {
            $action->execute($order, $request->validated('moadian_ref'));
        } catch (\RuntimeException $e) {
            return error($e->getMessage(), [], 422);
        } catch (Exception $e) {
            return error(__('orders.unknown_processing_error'), [], 500);
        }

        return success([], __('orders.system_moadian_submitted'));
    }
}
