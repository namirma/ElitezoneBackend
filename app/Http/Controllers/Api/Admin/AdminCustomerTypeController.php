<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Profile\CustomerTypeResource;
use App\Models\CustomerType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * پنل مدیریت — CRUD انواع مشتری (CustomerType).
 *
 * نوع مشتری (مثلاً «کارفرمای بدون الیت یار») پایه‌ی تعدیل قیمت (PriceAdjustment) است.
 * نوعی که تعدیل قیمت دارد قابل حذف نیست.
 */
class AdminCustomerTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $types = CustomerType::orderBy(CustomerType::NAME)->paginate($this->perPage(100));

        return success(CustomerTypeResource::collection($types), __('admin.customer_types.list_received'));
    }

    public function store(Request $request): JsonResponse
    {
        $type = CustomerType::create($request->validate($this->rules()));

        return created(new CustomerTypeResource($type), __('admin.customer_types.created'));
    }

    public function update(Request $request, CustomerType $customerType): JsonResponse
    {
        $customerType->update($request->validate($this->rules($customerType->id)));

        return success(new CustomerTypeResource($customerType), __('admin.customer_types.updated'));
    }

    public function destroy(CustomerType $customerType): JsonResponse
    {
        if ($customerType->priceAdjustments()->exists()) {
            return error(__('admin.customer_types.has_adjustments'), [], 422);
        }

        $customerType->delete();

        return success(null, __('admin.customer_types.deleted'));
    }

    private function rules(?int $ignoreId = null): array
    {
        $req = $ignoreId ? 'sometimes' : 'required';

        return [
            CustomerType::NAME => [$req, 'string', 'max:255'],
            CustomerType::CODE => [$req, 'string', 'max:255', Rule::unique('customer_types', 'code')->ignore($ignoreId)],
            CustomerType::HAS_ELITE_YAR => ['boolean'],
        ];
    }
}
