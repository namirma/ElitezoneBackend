<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Order\OrderUnitResource;
use App\Models\OrderUnit;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * پنل مدیریت — CRUD واحدهای سفارش (OrderUnit).
 *
 * `order_units.amount` ضریب تبدیل «هر واحد چند کیلوگرم» است و مبنای محاسبهٔ وزن در
 * سبد، کرایهٔ حمل و تسویه است — یعنی پرمصرف‌ترین جدول کاتالوگ. تا نشست ۲۱ هیچ روت
 * ادمینی نداشت و فقط با seed پر می‌شد.
 *
 * حذف: واحدی که در `product_order_units` یا روی `product_variants.limit_order_unit_id`
 * یا `products.aggregate_limit_order_unit_id` استفاده شده باشد **۴۲۲** می‌گیرد، نه
 * خطای FK. پیوت `cascadeOnDelete` دارد، پس بدون این گارد حذف یک واحد بی‌صدا سبدِ
 * محصولات را می‌شکست (افزودن به سبد ۴۲۲ می‌شد بدون اینکه ادمین بداند چرا).
 */
class AdminOrderUnitController extends Controller
{
    public function index(): JsonResponse
    {
        $units = OrderUnit::query()
            ->withCount('products')
            ->orderByDesc(OrderUnit::AMOUNT)
            ->paginate($this->perPage(50));

        return success(OrderUnitResource::collection($units), __('admin.order_units.list_received'));
    }

    public function show(OrderUnit $orderUnit): JsonResponse
    {
        $orderUnit->loadCount('products');

        return success(new OrderUnitResource($orderUnit), __('admin.order_units.received'));
    }

    public function store(Request $request): JsonResponse
    {
        $unit = OrderUnit::create($request->validate($this->rules()));

        return created(new OrderUnitResource($unit->refresh()), __('admin.order_units.created'));
    }

    public function update(Request $request, OrderUnit $orderUnit): JsonResponse
    {
        $orderUnit->update($request->validate($this->rules($orderUnit->id)));

        return success(new OrderUnitResource($orderUnit->refresh()), __('admin.order_units.updated'));
    }

    public function destroy(OrderUnit $orderUnit): JsonResponse
    {
        if ($this->isReferenced($orderUnit)) {
            return error(__('admin.order_units.delete_blocked'), [], 422);
        }

        $orderUnit->delete();

        return success(null, __('admin.order_units.deleted'));
    }

    /**
     * آیا این واحد جایی ارجاع داده شده؟ هر سه مسیرِ ارجاع بررسی می‌شود — پیوتِ محصول،
     * حدِ واریانت، و حدِ تجمیعیِ خطِ محصول.
     */
    private function isReferenced(OrderUnit $orderUnit): bool
    {
        return $orderUnit->products()->exists()
            || ProductVariant::where(ProductVariant::LIMIT_ORDER_UNIT_ID, $orderUnit->id)->exists()
            || Product::where(Product::AGGREGATE_LIMIT_ORDER_UNIT_ID, $orderUnit->id)->exists();
    }

    private function rules(?int $ignoreId = null): array
    {
        $req = $ignoreId ? 'sometimes' : 'required';

        return [
            OrderUnit::NAME => [$req, 'string', 'max:255'],
            OrderUnit::CODE => [$req, 'string', 'max:255', Rule::unique('order_units', 'code')->ignore($ignoreId)],
            // واحد اندازه‌گیری پایه (kg) — ستون NOT NULL است.
            OrderUnit::UNIT => [$req, 'string', 'max:50'],
            // amount ضریب تبدیل است؛ صفر یعنی تقسیم‌بر‌صفر در
            // convertQuantitiesToTargetUnitValue و وزنِ صفر در calculatePhysicalAttributes.
            // سقف = ظرفیت ستون decimal(10,2)، وگرنه به‌جای ۴۲۲ خطای دیتابیس می‌گیریم.
            OrderUnit::AMOUNT => [$req, 'numeric', 'min:0.01', 'max:99999999.99'],
        ];
    }
}
