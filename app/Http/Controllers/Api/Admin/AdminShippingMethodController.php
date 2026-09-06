<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Order\ShippingMethodResource;
use App\Models\CartItem;
use App\Models\ShippingMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * پنل مدیریت — CRUD روش‌های ارسال (ShippingMethod).
 *
 * قبل از این کنترلر، این جدول هیچ مسیرِ نوشتنی نداشت (فقط `GET /shipping-methods`
 * عمومی) — یعنی `delivery_notice`/`delivery_days` را کسی نمی‌توانست از پنل ادمین
 * تغییر دهد. روشی که در سبدهای فعال استفاده شده قابل حذف نیست تا آیتم‌های سبد
 * yatim نمانند.
 */
class AdminShippingMethodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $methods = ShippingMethod::query()
            ->orderBy(ShippingMethod::NAME)
            ->paginate($this->perPage(100));

        return success(ShippingMethodResource::collection($methods), __('admin.shipping_methods.list_received'));
    }

    public function store(Request $request): JsonResponse
    {
        $method = ShippingMethod::create($request->validate($this->rules()));

        return created(new ShippingMethodResource($method), __('admin.shipping_methods.created'));
    }

    public function update(Request $request, ShippingMethod $shippingMethod): JsonResponse
    {
        $shippingMethod->update($request->validate($this->rules($shippingMethod)));

        return success(new ShippingMethodResource($shippingMethod), __('admin.shipping_methods.updated'));
    }

    public function destroy(ShippingMethod $shippingMethod): JsonResponse
    {
        if (CartItem::where(CartItem::SHIPPING_METHOD_ID, $shippingMethod->id)->exists()) {
            return error(__('admin.shipping_methods.in_use'), [], 422);
        }

        $shippingMethod->delete();

        return success(null, __('admin.shipping_methods.deleted'));
    }

    private function rules(?ShippingMethod $existing = null): array
    {
        $partial = $existing !== null;
        $req = $partial ? 'sometimes' : 'required';

        return [
            ShippingMethod::NAME => [$req, 'string', 'max:255'],
            ShippingMethod::CODE => [
                $req, 'string', 'max:255',
                Rule::unique('shipping_methods', 'code')->ignore($existing?->id),
            ],
            ShippingMethod::DELIVERY_NOTICE => ['nullable', 'string', 'max:255'],
            ShippingMethod::DELIVERY_DAYS => ['nullable', 'integer', 'min:0', 'max:365'],
        ];
    }
}
