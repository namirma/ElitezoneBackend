<?php

namespace App\Http\Controllers\Api\User;

use App\Actions\Financial\RecordSupplierMoadianInvoiceAction;
use App\Actions\Order\ExtendReserveDeadlineAction;
use App\Enums\Order\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\ExtendReserveDeadlineRequest;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

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

        // وضعیت با فهرستِ واقعی محدود می‌شود: پیش از این هر رشته‌ای پذیرفته می‌شد و
        // یک تایپو به‌جای ۴۲۲ یک لیستِ خالی برمی‌گرداند — که از دید پنل با «سفارشی
        // نداری» یکسان است.
        $request->validate([
            // فهرست از enum می‌آید نه یک آرایهٔ دستیِ تازه: `EnumModelConstantParityTest`
            // تضمین می‌کند OrderStatus با `Order::STATUS_*` هم‌گام می‌ماند. مقدارِ
            // اعتبارسنجی‌شده همچنان string است، پس هیچ مقایسه‌ای در کد عوض نمی‌شود.
            'status' => ['nullable', 'string', Rule::in(array_column(OrderStatus::cases(), 'value'))],
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

        // `total_amount` عمداً مجموعِ `payment_to_supplier` است، نه `final_amount`.
        //
        // `final_amount` رقمِ خریدار است (کالا + کمیسیون + مالیات + کرایه) و به‌عنوان
        // «درآمدِ من» در داشبوردِ تأمین‌کننده گمراه‌کننده بود — عددی که هرگز دریافت
        // نمی‌کند. `payment_to_supplier` همان است که به کیف پولش می‌رود (پس از باسکول
        // با PTSM بازنویسی می‌شود). سفارشِ پرداخت‌نشده هنوز اسنپ‌شات ندارد، پس
        // `COALESCE` آن را صفر می‌کند نه NULL؛ `order_value` هم نگه داشته شده تا
        // ارزشِ کلِ سفارش در دسترس بماند، ولی حالا اسمش دروغ نمی‌گوید.
        $orderStats = Order::query()
            ->whereHas('items', $myItems)
            ->selectRaw(
                'status, COUNT(*) as count,'
                .' SUM(COALESCE(payment_to_supplier, 0)) as total_amount,'
                .' SUM(COALESCE(final_amount, 0)) as order_value'
            )
            ->groupBy('status')
            ->get()
            // getAttribute نه دسترسی جادویی: ستون‌های selectRaw برای larastan تعریف
            // نشده‌اند. `count` و `total_amount` هنوز از baseline می‌آیند؛ بقیه
            // مستقیم حل شده‌اند تا ورودیِ تازه‌ای به baseline اضافه نشود.
            ->map(fn ($row) => [
                'status' => $row->getAttribute('status'),
                'count' => (int) $row->count,
                'total_amount' => (float) $row->total_amount,
                'order_value' => (float) $row->getAttribute('order_value'),
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
                Order::STATUS_SHIPPED,
                Order::STATUS_PARTIALLY_DELIVERED,
                Order::STATUS_DELIVERED,
            ])
            ->with(Order::resourceRelations())
            ->latest()
            ->paginate($this->perPage(20));

        return success(OrderResource::collection($orders), __('admin.financial.pending_moadian_received'));
    }

    /**
     * POST /profile/orders/{order}/shipments/{shipment}/waybill — بند ۳.۷.
     *
     * بارنامه، مشخصات راننده و وزن باسکول با هم ثبت می‌شوند. تا فاز ۷ وزن از یک
     * اندپوینتِ ادمین می‌آمد و بارنامه از مسیری که در فاز ۰ حذف شده بود — یعنی
     * وضعیت «در حال حمل» را ادمین می‌نوشت، نه کسی که واقعاً بار را می‌فرستد.
     *
     * وقتی آخرین محموله‌ی سفارش ثبت شود، تسویه‌ی باسکول با **مجموع** وزن‌ها
     * اجرا می‌شود و سفارش به `shipped` می‌رود.
     */
    public function registerWaybill(
        \App\Http\Requests\Order\RegisterShipmentRequest $request,
        Order $order,
        \App\Models\Shipment $shipment,
        \App\Actions\Order\RegisterShipmentAction $action,
    ): JsonResponse {
        $this->authorize('manageAsSupplier', $order);

        $result = $action->execute($order, $shipment, $request->validated());

        $order->loadMissing(Order::resourceRelations());

        return success(
            new OrderResource($order->fresh(Order::resourceRelations())),
            $result['order_completed']
                ? __('shipments.registered_and_settled')
                : __('shipments.registered')
        );
    }

    /**
     * PATCH /profile/orders/{order}/shipments/{shipment}/weight — اصلاحِ وزن.
     *
     * ثبتِ بارنامه عمداً idempotent است (شماره‌ی داده‌شده به راننده نباید باطل
     * شود)، ولی همان گارد وزنِ اشتباه‌تایپ‌شده را هم قفل می‌کرد و تنها راهِ
     * اصلاح یک اندپوینتِ ادمین بود — روی عددی که مستقیماً مبلغِ تسویه را
     * تعیین می‌کند.
     *
     * مرزش در `CorrectShipmentWeightAction` است: پیش از تسویه ویرایشِ داده،
     * پس از آن ۴۰۹ — چون پول روی همان عدد جابه‌جا شده.
     */
    public function correctShipmentWeight(
        \App\Http\Requests\Order\CorrectShipmentWeightRequest $request,
        Order $order,
        \App\Models\Shipment $shipment,
        \App\Actions\Order\CorrectShipmentWeightAction $action,
    ): JsonResponse {
        $this->authorize('manageAsSupplier', $order);

        $corrected = $action->execute($order, $shipment, $request->validated());

        activity()
            ->performedOn($corrected)
            ->causedBy(Auth::user())
            ->withProperties(['actual_weight' => $corrected->{\App\Models\Shipment::ACTUAL_WEIGHT}])
            ->log('supplier.shipment.weight_corrected');

        return success(
            new \App\Http\Resources\Order\ShipmentResource($corrected),
            __('shipments.weight_corrected')
        );
    }

    /**
     * صدور دستور بارگیری اسپادانا.
     *
     * تنها متدِ این کنترلر بود که **هیچ گاردی نداشت**: نه وضعیت را چک می‌کرد و نه
     * idempotent بود، پس روی سفارشِ لغوشده هم اجرا می‌شد و هر بار صدا زدن، شناسه را
     * با `time()`ِ تازه بازنویسی می‌کرد — یعنی مرجعی که به سیستم بیرونی داده شده
     * بی‌صدا بی‌اعتبار می‌شد. حالا مثل بقیهٔ گذارها زیر قفل بررسی می‌شود.
     */
    /**
     * PUT /profile/orders/{order}/reserve-deadline — تمدید مهلت رزرو (بند ۳.۴).
     *
     * تمدید حقِ تأمین‌کننده است نه خریدار: کالا برای خریدار کنار گذاشته شده و
     * هزینه‌ی این قفلِ موجودی را تأمین‌کننده می‌دهد. در مدل جدید رایگان است —
     * `PayHoldFeeAction` و هزینه‌ی ۵ میلیون تومانی‌اش حذف شدند.
     */
    public function extendReserveDeadline(
        ExtendReserveDeadlineRequest $request,
        Order $order,
        ExtendReserveDeadlineAction $action,
    ): JsonResponse {
        $this->authorize('manageAsSupplier', $order);

        $updated = $action->execute($order, $request->date('reserve_deadline'));
        $updated->loadMissing(Order::resourceRelations());

        return success(new OrderResource($updated), __('orders.reserve_deadline.extended'));
    }

    public function issueEspadanaCommand(Order $order): JsonResponse
    {
        $this->authorize('manageAsSupplier', $order);

        return DB::transaction(function () use ($order) {
            /** @var Order $locked */
            $locked = Order::where(Order::ID, $order->id)->lockForUpdate()->first();

            if ($locked->{Order::ESPADANA_ORDER_REF} !== null) {
                return error(__('orders.espadana_already_issued'), [], 409);
            }

            if ($locked->status !== Order::STATUS_PROCESSING) {
                return error(__('orders.cannot_issue_espadana'), [], 400);
            }

            $locked->update([Order::ESPADANA_ORDER_REF => 'ESPADANA_'.$locked->id.'_'.time()]);

            $locked->loadMissing(Order::resourceRelations());

            return success(new OrderResource($locked), __('orders.espadana_command_issued'));
        });
    }

    /**
     * ضمیمه کردن رسید پرداخت توسط تأمین‌کننده.
     *
     * دو نقص داشت: گاردِ وضعیت نداشت (روی سفارشِ پرداخت‌نشده هم می‌نشست) و هنگام
     * جایگزینی، فایل قبلی را روی دیسکِ `financial` یتیم رها می‌کرد — دیسکی که
     * اسنادِ مالی حساس روی آن می‌نشیند و هیچ چرخهٔ پاک‌سازی‌ای ندارد.
     */
    public function attachPaymentReceipt(UploadOrderReceiptRequest $request, Order $order): JsonResponse
    {
        $this->authorize('manageAsSupplier', $order);

        if ($order->{Order::PAYMENT_STATUS} !== Order::PAYMENT_PAID) {
            return error(__('orders.cannot_attach_receipt_unpaid'), [], 400);
        }

        // آپلود فایل خارج از تراکنش انجام می‌شود تا قفل DB روی I/O دیسک نگه داشته نشود.
        // Private disk — supplier payment receipts must not be reachable via a public URL.
        $path = $request->file('receipt')->store('supplier_receipts', 'financial');

        return DB::transaction(function () use ($order, $path) {
            /** @var Order $locked */
            $locked = Order::where(Order::ID, $order->id)->lockForUpdate()->first();

            $previous = $locked->{Order::PAYMENT_RECEIPT_REF};

            $locked->update([Order::PAYMENT_RECEIPT_REF => $path]);

            // فایلِ جایگزین‌شده پاک می‌شود — ولی فقط بعد از اینکه ردیف با موفقیت
            // به‌روز شد، وگرنه rollback رسیدی را پاک کرده بود که هنوز مرجعش زنده است.
            if ($previous && $previous !== $path && Storage::disk('financial')->exists($previous)) {
                Storage::disk('financial')->delete($previous);
            }

            $locked->loadMissing(Order::resourceRelations());

            return success(new OrderResource($locked), __('orders.receipt_attached'));
        });
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
            // هم‌الگو با submitEliteYARMoadianInvoice: این متد هولد مالیات (WVAT) آزاد
            // می‌کند — شکستِ بی‌لاگ آن دیباگ‌ناپذیر بود.
            Log::channel('orders')->error('submitSupplierMoadianInvoice failed', [
                'order_id' => $order->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return error(__('orders.unknown_processing_error'), [], 500);
        }

        return success([], __('orders.supplier_moadian_submitted'));
    }
}
