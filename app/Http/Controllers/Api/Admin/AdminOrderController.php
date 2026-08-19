<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Financial\RecordSystemMoadianInvoiceAction;
use App\Actions\Order\CancelOrderAction;
use App\Actions\Shipping\RecordShipmentArrivalAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrderFilterRequest;
use App\Http\Requests\Order\SubmitMoadianInvoiceRequest;
use App\Http\Resources\Order\OrderResource;
use App\Http\Resources\Order\ShipmentResource;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Order\OrderTimelineService;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

    /**
     * روایتِ کاملِ سفارش — ادغامِ چرخهٔ عمر، گذارهای وضعیت، حرکت‌های پول و اسناد.
     *
     * مکملِ `history()` است نه جایگزینش: آن لاگِ spatie را می‌دهد (چه فیلدی عوض شد)،
     * این پنج منبع را کنار هم می‌چیند و فاصلهٔ زمانیِ بینشان را هم نشان می‌دهد.
     */
    public function timeline(Order $order, OrderTimelineService $timeline): JsonResponse
    {
        return success($timeline->build($order), __('admin.order_timeline_received'));
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
        // بند ۵.۳ سند بازطراحی: **تنها اختیار ادمین روی وضعیت، لغو اجباری است.**
        //
        // هر گذارِ دیگری حالا صاحبِ مشخصی دارد که لِگ مالی و موجودی را هم با خودش
        // می‌برد: `processing` از مسیر پرداخت می‌آید، `shipped` از ثبت بارنامه،
        // `delivered`/`partially_delivered` از تأیید خریدار، و `returned_to_origin`
        // از انقضای مهلت تحویل. یک `update([STATUS => ...])` خام از پنل ادمین همان
        // گذار را بدون هیچ‌کدام از آن اثرها می‌ساخت — سفارشی که «تحویل شده» است ولی
        // هیچ محموله‌ای تأیید نشده، یا «در حال حمل» بدون بارنامه.
        $request->validate(['status' => 'required|string|in:'.Order::STATUS_CANCELLED]);

        // Cancelling MUST go through CancelOrderAction so the full reversal runs:
        // wallet refund (buyer + supplier legs), stock restoration, OrderCancelled
        // event and the refund/cancel documents. A bare status update here would
        // corrupt the ledger and inventory. isSystemCron=true lets the back-office
        // operator also unwind reserved orders.
        try {
            $cancelAction->execute($order, __('admin.orders.cancelled_by_admin'), true, true);
        } catch (Exception $e) {
            Log::error('Admin order cancel failed: '.$e->getMessage(), ['order_id' => $order->id]);

            return error(__('orders.cannot_cancel'), [], 422);
        }

        return success(null, __('orders.cancelled_successfully'));
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

    /**
     * POST /admin/shipments/{shipment}/confirm-arrival — بند ۳.۱۰ و ۷.۶.
     *
     * «بک‌آفیس هم می‌تواند با تماس تلفنی تأیید کند (با ثبت در تاریخچه)». همان
     * اکشنِ لینکِ راننده اجرا می‌شود؛ فقط `arrival_source` فرق می‌کند تا بعداً
     * معلوم باشد این رسیدن را چه کسی اعلام کرده است.
     */
    public function confirmShipmentArrival(
        Shipment $shipment,
        RecordShipmentArrivalAction $action,
    ): JsonResponse {
        $action->execute($shipment, Shipment::ARRIVAL_BACKOFFICE, (int) Auth::id());

        activity()
            ->performedOn($shipment)
            ->causedBy(Auth::user())
            ->withProperties(['order_id' => $shipment->{Shipment::ORDER_ID}])
            ->log('admin.shipment.arrival_confirmed');

        return success(
            new ShipmentResource($shipment->fresh()),
            __('shipments.arrival_recorded')
        );
    }
}
