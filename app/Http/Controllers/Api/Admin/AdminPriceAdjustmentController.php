<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Product\PriceAdjustmentResource;
use App\Models\PriceAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * پنل مدیریت — CRUD تعدیل قیمت (PriceAdjustment).
 *
 * هر ردیف یک تعدیل برای ترکیب (نوع مشتری × نشان) است؛ این ترکیب یکتاست (badge می‌تواند
 * null باشد = تعدیل پایه‌ی آن نوع مشتری بدون نشان). هویت ردیف ثابت است؛ در update فقط
 * مبلغ و وضعیت فعال‌بودن تغییر می‌کند.
 */
class AdminPriceAdjustmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'customer_type_id' => ['nullable', 'integer'],
            'badge_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $adjustments = PriceAdjustment::query()
            ->when($request->filled('customer_type_id'), fn ($q) => $q->where(PriceAdjustment::CUSTOMER_TYPE_ID, $request->integer('customer_type_id')))
            ->when($request->filled('badge_id'), fn ($q) => $q->where(PriceAdjustment::BADGE_ID, $request->integer('badge_id')))
            ->with(['customerType', 'badge'])
            ->latest(PriceAdjustment::ID)
            ->paginate($this->perPage(100));

        return success(PriceAdjustmentResource::collection($adjustments), __('admin.price_adjustments.list_received'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $badgeId = $data[PriceAdjustment::BADGE_ID] ?? null;
        $duplicate = PriceAdjustment::query()
            ->where(PriceAdjustment::CUSTOMER_TYPE_ID, $data[PriceAdjustment::CUSTOMER_TYPE_ID])
            ->when($badgeId === null,
                fn ($q) => $q->whereNull(PriceAdjustment::BADGE_ID),
                fn ($q) => $q->where(PriceAdjustment::BADGE_ID, $badgeId)
            )
            ->exists();

        if ($duplicate) {
            return error(__('admin.price_adjustments.duplicate'), [], 422);
        }

        $adjustment = PriceAdjustment::create($data);

        return created(new PriceAdjustmentResource($adjustment->load(['customerType', 'badge'])), __('admin.price_adjustments.created'));
    }

    public function update(Request $request, PriceAdjustment $priceAdjustment): JsonResponse
    {
        $priceAdjustment->update($request->validate($this->updateRules()));

        return success(new PriceAdjustmentResource($priceAdjustment->load(['customerType', 'badge'])), __('admin.price_adjustments.updated'));
    }

    public function destroy(PriceAdjustment $priceAdjustment): JsonResponse
    {
        $priceAdjustment->delete();

        return success(null, __('admin.price_adjustments.deleted'));
    }

    /** Create needs the full identity (customer_type + optional badge) + amount. */
    private function rules(): array
    {
        return [
            PriceAdjustment::CUSTOMER_TYPE_ID => ['required', 'integer', 'exists:customer_types,id'],
            PriceAdjustment::BADGE_ID => ['nullable', 'integer', 'exists:badges,id'],
            PriceAdjustment::ADJUSTMENT_AMOUNT => ['required', 'numeric'],
            PriceAdjustment::IS_ACTIVE => ['boolean'],
        ];
    }

    /** Identity (customer_type × badge) is fixed on update; only amount/active mutate. */
    private function updateRules(): array
    {
        return [
            PriceAdjustment::ADJUSTMENT_AMOUNT => ['sometimes', 'required', 'numeric'],
            PriceAdjustment::IS_ACTIVE => ['boolean'],
        ];
    }
}
