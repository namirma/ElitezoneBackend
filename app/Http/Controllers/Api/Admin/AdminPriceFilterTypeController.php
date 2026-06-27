<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Product\PriceFilterTypeResource;
use App\Models\PriceFilterType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * پنل مدیریت — CRUD نوع فیلتر قیمت (PriceFilterType).
 *
 * apply_on مشخص می‌کند فیلتر روی کدام قیمت اعمال شود: general / special / both.
 * برخلاف PriceFilter و PriceFilterCategory (که خودشان کش را فلاش می‌کنند)، این مدل
 * booted ندارد؛ پس بعد از هر تغییر، کلید کش قیمت‌گذاری را دستی فلاش می‌کنیم.
 */
class AdminPriceFilterTypeController extends Controller
{
    private const PRICING_CACHE_KEY = 'pricing:categories_with_filters';

    private const APPLY_ON = ['general', 'special', 'both'];

    public function index(): JsonResponse
    {
        $types = PriceFilterType::orderBy(PriceFilterType::SORT_ORDER)
            ->orderBy(PriceFilterType::NAME)
            ->paginate($this->perPage(100));

        return success(PriceFilterTypeResource::collection($types), __('admin.filter_types.list_received'));
    }

    public function store(Request $request): JsonResponse
    {
        $type = PriceFilterType::create($request->validate($this->rules()));
        Cache::forget(self::PRICING_CACHE_KEY);

        return created(new PriceFilterTypeResource($type), __('admin.filter_types.created'));
    }

    public function update(Request $request, PriceFilterType $filterType): JsonResponse
    {
        $filterType->update($request->validate($this->rules($filterType->id)));
        Cache::forget(self::PRICING_CACHE_KEY);

        return success(new PriceFilterTypeResource($filterType), __('admin.filter_types.updated'));
    }

    public function destroy(PriceFilterType $filterType): JsonResponse
    {
        if ($filterType->filters()->exists()) {
            return error(__('admin.filter_types.has_filters'), [], 422);
        }

        $filterType->delete();
        Cache::forget(self::PRICING_CACHE_KEY);

        return success(null, __('admin.filter_types.deleted'));
    }

    public function toggle(PriceFilterType $filterType): JsonResponse
    {
        $filterType->update([PriceFilterType::IS_ACTIVE => ! $filterType->{PriceFilterType::IS_ACTIVE}]);
        Cache::forget(self::PRICING_CACHE_KEY);

        $message = $filterType->{PriceFilterType::IS_ACTIVE}
            ? __('admin.filter_types.toggled_active')
            : __('admin.filter_types.toggled_inactive');

        return success(new PriceFilterTypeResource($filterType), $message);
    }

    private function rules(?int $ignoreId = null): array
    {
        $req = $ignoreId ? 'sometimes' : 'required';

        return [
            PriceFilterType::NAME => [$req, 'string', 'max:255'],
            PriceFilterType::CODE => [$req, 'string', 'max:255', Rule::unique('price_filter_types', 'code')->ignore($ignoreId)],
            PriceFilterType::APPLY_ON => [$req, Rule::in(self::APPLY_ON)],
            PriceFilterType::IS_ACTIVE => ['boolean'],
            PriceFilterType::SORT_ORDER => ['nullable', 'integer', 'min:0'],
        ];
    }
}
