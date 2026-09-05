<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Financial\RecordSystemMoadianInvoiceAction;
use App\Actions\Order\CancelOrderAction;
use App\Actions\Order\CorrectShipmentWeightAction;
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
        return $this->forceCancel($request, $order, $cancelAction);
    }

    /**
     * POST /api/admin/orders/{order}/force-cancel — §۷.۶.
     *
     * همان مسیرِ `changeStatus` با نامِ صریح. `isSystemCron=true` عمدی است: ادمین
     * باید بتواند رزروی را هم که هنوز در پنجره‌ی لغو خریدار نیست باز کند —
     * دقیقاً همان استثنایی که کرانِ انقضا دارد.
     *
     * دلیلِ عبور از `CancelOrderAction` و نه یک `update([STATUS => …])` خام:
     * برگشتِ کامل لازم است — استرداد کیف پول (لِگ خریدار و تأمین‌کننده)،
     * بازگرداندن موجودی، رویداد `OrderCancelled` و اسناد لغو. یک نوشتنِ خام
     * دفتر و انبار را همزمان خراب می‌کرد.
     */
    public function forceCancel(Request $request, Order $order, CancelOrderAction $cancelAction): JsonResponse
    {
        // بند ۳.۶: «لغو اجباری ادمین: **فقط پس از تیکت، با ثبت دلیل**».
        //
        // هر دو شرط اینجا اجباری‌اند، نه اختیاری. این تنها اقدامی است که یک
        // سفارشِ پرداخت‌شده را یک‌طرفه برمی‌گرداند و موجودی را آزاد می‌کند، پس
        // بدون تیکت و دلیل هیچ ردی نمی‌ماند که چرا انجام شده. `reason` پیش‌تر
        // به یک متنِ پیش‌فرض fallback می‌کرد — یعنی «ثبت دلیل» عملاً اختیاری بود
        // و لاگ همیشه یک جمله‌ی یکسانِ بی‌اطلاع می‌گرفت.
        $data = $request->validate([
            'ticket_id' => ['required', 'integer', 'exists:tickets,id'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'ticket_id.required' => __('admin.orders.force_cancel_ticket_required'),
            'ticket_id.exists' => __('admin.orders.force_cancel_ticket_missing'),
            'reason.required' => __('admin.orders.force_cancel_reason_required'),
            'reason.min' => __('admin.orders.force_cancel_reason_too_short'),
        ]);

        try {
            $cancelAction->execute($order, $data['reason'], true, true);
        } catch (Exception $e) {
            Log::error('Admin order cancel failed: '.$e->getMessage(), ['order_id' => $order->id]);

            return error(__('orders.cannot_cancel'), [], 422);
        }

        activity()
            ->performedOn($order)
            ->causedBy(Auth::user())
            ->withProperties(['reason' => $data['reason'], 'ticket_id' => $data['ticket_id']])
            ->log('admin.order.force_cancelled');

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
    /**
     * POST /api/admin/shipments/{shipment}/weight — adapter دستی (§۷.۶، بند ۳.۷).
     *
     * سند می‌گوید `ManualEntryAdapter` «ثبت دستی **با تأیید بک‌آفیس**» است —
     * این همان تأیید است. عدد از `WeightSourceResolver` می‌گذرد و نه مستقیم از
     * بدنه‌ی درخواست، پس گاردهای adapter (مثبت‌بودن و بازه‌ی معقول نسبت به وزنِ
     * برنامه‌ریزی‌شده) اینجا هم اعمال می‌شوند.
     *
     * ⚠️ **هیچ لِگ مالی‌ای اینجا اجرا نمی‌شود.** ثبت وزن فقط وزن را می‌نویسد؛
     * تعدیل یک‌بار و پس از بارنامه‌ی نهایی از `RegisterShipmentAction` می‌آید
     * (بند ۸.۲). نوشتنِ لِگ اینجا یعنی تعدیلِ دوباره روی همان محموله.
     */
    public function recordShipmentWeight(
        Request $request,
        Shipment $shipment,
        CorrectShipmentWeightAction $action,
    ): JsonResponse {
        $data = $request->validate([
            'actual_weight' => ['required', 'numeric', 'min:0.001'],
            'source' => ['sometimes', 'string', 'in:manual,ocr,api'],
        ]);

        // ⚠️ این متد وزن را با یک `forceFill()->save()` بدونِ تراکنش، بدونِ
        // `lockForUpdate` و بدونِ هیچ بررسیِ وضعیتی می‌نوشت. یعنی دو ثبتِ
        // هم‌زمان با هم مسابقه می‌دادند، و بدتر: وزن می‌توانست **پس از** اجرای
        // تسویه بازنویسی شود — سفارش عددی نشان می‌داد که با دفترِ کیف پول
        // نمی‌خواند، بی‌آنکه هیچ لِگی برگردانده شود.
        //
        // حالا از همان اکشنی رد می‌شود که مسیرِ تأمین‌کننده استفاده می‌کند، پس
        // قفل و مرزِ «پیش از تسویه» یک تعریف دارند نه دو تا.
        $corrected = $action->execute($shipment->order, $shipment, $data);

        activity()
            ->performedOn($corrected)
            ->causedBy(Auth::user())
            ->withProperties([
                'actual_weight' => $corrected->{Shipment::ACTUAL_WEIGHT},
                'source' => $corrected->{Shipment::WEIGHT_SOURCE},
            ])
            ->log('admin.shipment.weight_recorded');

        return success(new ShipmentResource($corrected), __('shipments.weight_recorded'));
    }

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
